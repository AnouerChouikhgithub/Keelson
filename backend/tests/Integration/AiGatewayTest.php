<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ai\AiFeatureFlags;
use App\Ai\AiGateway;
use App\Ai\AiRequest;
use App\Ai\FakeAiProvider;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Repository\OrganizationAiSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gateway is the only path to a provider, so these tests are the policy: the
 * deployment switch, the tenant opt-in, the budget and the rate limits all stop a call
 * before it is made, and a provider failure neither bills the tenant nor hides.
 */
final class AiGatewayTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AiGateway $gateway;
    private AiFeatureFlags $flags;
    private FakeAiProvider $fake;
    private Organization $organization;
    private Principal $principal;
    private OrganizationAiSettings $settings;
    private int $budget = 1000000;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $gateway = $container->get(AiGateway::class);
        self::assertInstanceOf(AiGateway::class, $gateway);
        $this->gateway = $gateway;

        $flags = $container->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $fake = $container->get(FakeAiProvider::class);
        self::assertInstanceOf(FakeAiProvider::class, $fake);
        $this->fake = $fake;
        $this->fake->failWith(FakeAiProvider::FAILURE_NONE);

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('AI Gateway '.$suffix, 'ai-gateway-'.$suffix);
        $this->principal = new Principal('ai-gateway-'.$suffix.'@example.test', 'AI Gateway');
        $this->settings = new OrganizationAiSettings($this->organization, true, $this->budget);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->persist($this->settings);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);
        $this->fake?->failWith(FakeAiProvider::FAILURE_NONE);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testTheGlobalSwitchStopsTheCallBeforeAnyProvider(): void
    {
        $this->flags->overrideGlobalEnabledForTests(false);

        $exception = $this->failure();
        self::assertSame('ai_disabled', $exception->getErrorCode());
        self::assertSame(403, $exception->getStatusCode());
        self::assertSame([], $this->ledgerRows());
    }

    public function testAnOrganizationThatHasNotOptedInIsDisabled(): void
    {
        $this->settings->update(false, $this->budget);
        $this->entityManager->flush();

        self::assertSame('ai_disabled', $this->failure()->getErrorCode());
        self::assertSame([], $this->ledgerRows());
    }

    public function testAForeignCallerGetsNotFoundNotADisabled(): void
    {
        $stranger = new Principal('ai-gateway-stranger-'.bin2hex(random_bytes(6)).'@example.test', 'Stranger');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        try {
            $this->gateway->complete($stranger, $this->organization->getId(), $this->request(10, $stranger));
            self::fail('A non-member must not reach the gateway rules.');
        } catch (ApiException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
            self::assertSame(404, $exception->getStatusCode());
        } finally {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM principals WHERE id = ?', [$stranger->getId()]);
        }
    }

    public function testARequestThatWouldExceedTheBudgetIsRefused(): void
    {
        $this->settings->update(true, 100);
        $this->entityManager->flush();
        $this->settle(90);

        $exception = $this->failure(60);
        self::assertSame('ai_budget_exceeded', $exception->getErrorCode());
        self::assertSame(402, $exception->getStatusCode());
        self::assertSame([], $this->ledgerRows());
    }

    /**
     * A nearly empty budget can fund one request, not two: the second reservation
     * sees the first one's committed usage.
     */
    public function testANearlyEmptyBudgetCannotFundTwoRequests(): void
    {
        $this->settings->update(true, 110);
        $this->entityManager->flush();
        $this->settle(20);

        $first = $this->gateway->complete($this->principal, $this->organization->getId(), $this->request(60));
        self::assertNotSame('', $first->text);

        $exception = $this->failure(60);
        self::assertSame('ai_budget_exceeded', $exception->getErrorCode());
    }

    public function testThePrincipalBucketStopsALoop(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->gateway->complete($this->principal, $this->organization->getId(), $this->request(10));
        }

        $exception = $this->failure(10);
        self::assertSame('ai_rate_limited', $exception->getErrorCode());
        self::assertSame(429, $exception->getStatusCode());
        self::assertArrayHasKey('Retry-After', $exception->getHeaders());
    }

    public static function providerFailures(): iterable
    {
        yield 'timeout' => [FakeAiProvider::FAILURE_TIMEOUT, 'timeout'];
        yield 'malformed' => [FakeAiProvider::FAILURE_MALFORMED, 'malformed_body'];
        yield 'server error' => [FakeAiProvider::FAILURE_SERVER_ERROR, 'server_error'];
    }

    #[DataProvider('providerFailures')]
    public function testAProviderFailureReleasesTheReservationAndWritesAFailedRow(string $failure, string $reason): void
    {
        $this->fake->failWith($failure);

        $exception = $this->failure();
        self::assertSame('ai_unavailable', $exception->getErrorCode());
        self::assertSame(503, $exception->getStatusCode());

        $rows = $this->ledgerRows();
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame($reason, $rows[0]['error_code']);
        self::assertSame(0, (int) $rows[0]['total_tokens']);

        self::assertSame(0, $this->reservedTokens(), 'A failed call must not keep its reservation.');
        self::assertSame(0, $this->usedTokens(), 'A failed call must not bill the tenant.');
    }

    public function testASuccessfulCallRecordsRealUsage(): void
    {
        $response = $this->gateway->complete($this->principal, $this->organization->getId(), $this->request(50));

        self::assertStringContainsString('Fake AI answer', $response->text);
        self::assertGreaterThan(0, $response->totalTokens());

        $rows = $this->ledgerRows();
        self::assertCount(1, $rows);
        self::assertSame('succeeded', $rows[0]['status']);
        self::assertSame('guide', $rows[0]['feature']);
        self::assertSame((string) $this->principal->getId(), $rows[0]['principal_id']);
        self::assertNull($rows[0]['error_code']);

        self::assertSame(0, $this->reservedTokens());
        self::assertSame($response->totalTokens(), $this->usedTokens());
        self::assertNotNull($rows[0]['latency_ms'], 'The ledger records the call duration.');
    }

    private function request(int $maxTokens = 100, ?Principal $principal = null): AiRequest
    {
        return new AiRequest(
            'guide',
            'You are a helpful assistant.',
            'How do I mark an invoice paid?',
            $maxTokens,
            $this->organization->getId(),
            ($principal ?? $this->principal)->getId(),
        );
    }

    private function failure(int $maxTokens = 100): ApiException
    {
        try {
            $this->gateway->complete($this->principal, $this->organization->getId(), $this->request($maxTokens));
            self::fail('The gateway should have refused this request.');
        } catch (ApiException $exception) {
            return $exception;
        }
    }

    /** Sets used tokens without a provider call, as an earlier request would have. */
    private function settle(int $used): void
    {
        $repository = self::getContainer()->get(OrganizationAiSettingsRepository::class);
        self::assertInstanceOf(OrganizationAiSettingsRepository::class, $repository);
        $repository->settleBudget($this->organization->getId(), (new \DateTimeImmutable())->format('Y-m'), 0, $used);
    }

    /** @return list<array<string, mixed>> */
    private function ledgerRows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT status, feature, principal_id, error_code, total_tokens, latency_ms FROM ai_requests WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function reservedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function usedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT used_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }
}
