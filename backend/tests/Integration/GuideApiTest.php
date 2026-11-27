<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ai\AiFeatureFlags;
use App\Ai\FakeAiProvider;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Guide\GuideMessages;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The guide over HTTP, with the deterministic provider.
 *
 * The fake provider is the seam that makes "no provider call" observable: a call always
 * writes an `ai_requests` ledger row, so an empty ledger is proof that an uncovered
 * question, or an exhausted budget, never reached a model.
 */
final class GuideApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Organization $organization;
    private Organization $otherOrganization;
    private OrganizationAiSettings $settings;
    private Principal $owner;
    private Principal $viewer;
    private AiFeatureFlags $flags;
    private FakeAiProvider $fake;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        $flags = self::getContainer()->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $fake = self::getContainer()->get(FakeAiProvider::class);
        self::assertInstanceOf(FakeAiProvider::class, $fake);
        $this->fake = $fake;
        $this->fake->failWith(FakeAiProvider::FAILURE_NONE);

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Guide '.$suffix, 'guide-'.$suffix);
        $this->otherOrganization = new Organization('Guide Other '.$suffix, 'guide-other-'.$suffix);
        $this->owner = new Principal('guide-owner-'.$suffix.'@example.test', 'Guide Owner');
        $this->viewer = new Principal('guide-viewer-'.$suffix.'@example.test', 'Guide Viewer');
        $this->settings = new OrganizationAiSettings($this->organization, true, 1000000);

        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->otherOrganization);
        $this->entityManager->persist($this->owner);
        $this->entityManager->persist($this->viewer);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->owner, 'Owner'));
        $this->entityManager->persist(new Membership($this->organization, $this->viewer, 'Viewer'));
        $this->entityManager->persist($this->settings);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);
        $this->fake?->failWith(FakeAiProvider::FAILURE_NONE);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'DELETE FROM ai_requests WHERE organization_id IN (?, ?)',
                [$this->organization->getId(), $this->otherOrganization->getId()],
            );
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', [
                $this->organization->getId(),
                $this->otherOrganization->getId(),
            ]);
            $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [
                $this->owner->getId(),
                $this->viewer->getId(),
            ]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testAnonymousCallersAreRejected(): void
    {
        $this->client->request('POST', '/api/ai/guide', server: ['CONTENT_TYPE' => 'application/json'], content: '{"question":"How do invoices work?"}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheQuestionAndLocaleAreValidated(): void
    {
        $this->client->loginUser($this->owner);

        $this->ask(['question' => 'hi']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('question', $this->payload()['error']['fields']);

        $this->ask(['question' => str_repeat('a', 501)]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('question', $this->payload()['error']['fields']);

        $this->ask(['question' => 'How do invoices work?', 'locale' => 'de']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('locale', $this->payload()['error']['fields']);

        self::assertSame([], $this->ledger(), 'A refused request must not reach a provider.');
    }

    public function testAnOutOfCorpusQuestionIsAnsweredLocallyAndCostsNothing(): void
    {
        $this->client->loginUser($this->owner);

        $this->ask(['question' => 'What is the capital of France?']);
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertSame(GuideMessages::notCovered('en'), $payload['answer']);
        self::assertSame([], $payload['sources']);
        self::assertSame([], $this->ledger(), 'No retrieval means no model call.');

        $this->ask(['question' => 'ما هي عاصمة فرنسا؟', 'locale' => 'ar']);
        self::assertResponseIsSuccessful();
        self::assertSame(GuideMessages::notCovered('ar'), $this->payload()['answer']);
        self::assertSame([], $this->ledger());
    }

    public function testACoveredQuestionIsAnsweredFromTheArticlesAndRecorded(): void
    {
        $this->client->loginUser($this->owner);

        $this->ask(['question' => 'How do I mark an invoice paid?']);
        self::assertResponseIsSuccessful();
        $payload = $this->payload();
        self::assertStringContainsString('Fake AI answer for guide', $payload['answer']);
        self::assertNotSame([], $payload['sources']);
        self::assertSame(['id', 'title'], array_keys($payload['sources'][0]));
        self::assertSame('invoice-lifecycle', $payload['sources'][0]['id']);

        $rows = $this->ledger();
        self::assertCount(1, $rows);
        self::assertSame('guide', $rows[0]['feature']);
        self::assertSame('succeeded', $rows[0]['status']);
    }

    public function testAnOwnerGetsTheOwnerOnlyArticleAndAViewerNeverDoes(): void
    {
        $question = 'How do I enable AI and change the monthly token budget?';

        $this->client->loginUser($this->owner);
        $this->ask(['question' => $question]);
        self::assertResponseIsSuccessful();
        self::assertContains('ai-settings', array_column($this->payload()['sources'], 'id'));

        $this->client->loginUser($this->viewer);
        $this->ask(['question' => $question]);
        self::assertResponseIsSuccessful();
        self::assertNotContains('ai-settings', array_column($this->payload()['sources'], 'id'));
    }

    public function testAnExhaustedBudgetAnswersTheContractCode(): void
    {
        $this->settings->update(true, 0);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner);
        $this->ask(['question' => 'How do I mark an invoice paid?']);

        self::assertResponseStatusCodeSame(402);
        self::assertSame('ai_budget_exceeded', $this->payload()['error']['code']);
        self::assertSame([], $this->ledger());
    }

    public function testATenantThatHasNotEnabledAiAnswersTheContractCode(): void
    {
        $this->settings->update(false, 1000000);
        $this->entityManager->flush();

        $this->client->loginUser($this->owner);
        $this->ask(['question' => 'How do I mark an invoice paid?']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('ai_disabled', $this->payload()['error']['code']);
        self::assertSame([], $this->ledger());
    }

    public function testThePrincipalRateLimitAnswersTheContractCode(): void
    {
        $this->client->loginUser($this->owner);
        $body = ['question' => 'How do I send an invoice?'];

        // The principal bucket allows 20 calls per five minutes; a fresh principal has
        // an empty bucket history because the key is its id.
        for ($call = 1; $call <= 20; ++$call) {
            $this->ask($body);
            self::assertResponseIsSuccessful('Call '.$call.' should still be accepted.');
        }

        $this->ask($body);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('ai_rate_limited', $this->payload()['error']['code']);
        self::assertNotSame('', (string) $this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * The live run left no row for a guide 503: the exception escapes the controller, the
     * request listener rolls the request transaction back, and the failed row written by
     * the gateway went with it. Each provider failure must leave exactly one row and no
     * reservation, whether or not the request commits.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function providerFailures(): iterable
    {
        yield 'not configured' => [FakeAiProvider::FAILURE_NOT_CONFIGURED, 'not_configured'];
        yield 'transport (bad key, wrong model, unreachable host)' => [FakeAiProvider::FAILURE_TRANSPORT, 'transport'];
        yield 'timeout' => [FakeAiProvider::FAILURE_TIMEOUT, 'timeout'];
        yield 'server error' => [FakeAiProvider::FAILURE_SERVER_ERROR, 'server_error'];
        yield 'malformed body' => [FakeAiProvider::FAILURE_MALFORMED, 'malformed_body'];
        yield 'empty answer' => [FakeAiProvider::FAILURE_EMPTY, 'empty_after_sanitize'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerFailures')]
    public function testAProviderFailureLeavesExactlyOneFailedRow(string $failure, string $reason): void
    {
        $this->fake->failWith($failure);
        $this->client->loginUser($this->owner);

        $this->ask(['question' => 'How do I send an invoice?']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('ai_unavailable', $this->payload()['error']['code']);

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT status, error_code, total_tokens FROM ai_requests WHERE organization_id = ?',
            [$this->organization->getId()],
        );
        self::assertCount(1, $rows, 'A failed guide call must still be evidence.');
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame($reason, $rows[0]['error_code']);
        self::assertSame(0, (int) $rows[0]['total_tokens']);

        $reserved = $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
        self::assertSame(0, (int) $reserved, 'The rolled-back reservation must not linger.');
    }

    public function testAForeignOrganizationIsNotFound(): void
    {
        $this->client->loginUser($this->owner);

        $this->ask(['question' => 'How do I send an invoice?', 'organizationId' => $this->otherOrganization->getId()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->payload()['error']['code']);

        $this->ask(['question' => 'How do I send an invoice?', 'organizationId' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_id', $this->payload()['error']['code']);
    }

    /** @param array<string, mixed> $body */
    private function ask(array $body): void
    {
        $this->client->request('POST', '/api/ai/guide', server: $this->headers(), content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT status, feature FROM ai_requests WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }
}
