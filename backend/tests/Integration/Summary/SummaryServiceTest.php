<?php

declare(strict_types=1);

namespace App\Tests\Integration\Summary;

use App\Ai\AiFeatureFlags;
use App\Ai\Exception\AiBudgetExceededException;
use App\Ai\Exception\AiRateLimitedException;
use App\Ai\Exception\AiUnavailableException;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Repository\OrganizationAiSettingsRepository;
use App\Summary\GroundingCheck;
use App\Summary\SummaryFallback;
use App\Summary\SummaryKind;
use App\Summary\SummaryService;
use App\Summary\WorkspaceFactsBuilder;
use App\Tests\Support\ScriptedSummaryTextGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Generation, proof and fallback, with the database and the real gateway rules.
 *
 * The generator is scripted so the interesting cases are possible: an answer
 * that quotes the facts, and an answer that invents a figure. Budget and
 * rate-limit refusals must escape unchanged — a summary is not worth turning a
 * 402 into a 200.
 */
final class SummaryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SummaryService $service;
    private ScriptedSummaryTextGenerator $generator;
    private WorkspaceFactsBuilder $facts;
    private AiFeatureFlags $flags;
    private Organization $organization;
    private Principal $principal;
    private OrganizationAiSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $flags = $container->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $settings = $container->get(OrganizationAiSettingsRepository::class);
        self::assertInstanceOf(OrganizationAiSettingsRepository::class, $settings);

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Summary Service '.$suffix, 'summary-service-'.$suffix);
        $this->principal = new Principal('summary-service-'.$suffix.'@example.test', 'Service Owner');
        $this->settings = new OrganizationAiSettings($this->organization, true, 1000000);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->persist($this->settings);

        $customer = new Customer($this->organization, 'Acme '.$suffix);
        $this->entityManager->persist($customer);
        $this->entityManager->persist(new Invoice(
            $this->organization,
            $customer,
            'SS-'.$suffix,
            $this->principal->getId(),
            new \DateTimeImmutable('today'),
            (new \DateTimeImmutable('today'))->modify('+14 days'),
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '100.000', 'lineTotal' => '100.000']],
            null,
            null,
            '100.000',
            '0.000',
            '100.000',
        ));
        $this->entityManager->flush();

        $this->facts = new WorkspaceFactsBuilder(
            $this->entityManager->getConnection(),
            $this->entityManager->getRepository(Membership::class),
            $this->entityManager->getRepository(Invoice::class),
        );
        $this->generator = new ScriptedSummaryTextGenerator();
        $this->service = new SummaryService(
            $this->facts,
            $this->generator,
            new GroundingCheck(),
            new SummaryFallback(),
            $this->flags,
            $settings,
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testAGroundedAnswerIsReturnedAsWritten(): void
    {
        $this->generator->script(['This workspace has 1 customer and 1 invoice.']);

        $result = $this->summarize();

        self::assertFalse($result['fallback']);
        self::assertSame('This workspace has 1 customer and 1 invoice.', $result['text']);
        self::assertSame(1, $this->generator->calls());
        self::assertSame('overview', $result['facts']['kind']);
        self::assertNotNull(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['generatedAt']) ?: null);
    }

    public function testTheSameFactsAreServedFromTheCache(): void
    {
        $this->generator->script([
            'This workspace has 1 customer and 1 invoice.',
            'A second answer that must not be asked for.',
        ]);

        $first = $this->summarize();
        $second = $this->summarize();

        self::assertSame($first['text'], $second['text']);
        self::assertSame(1, $this->generator->calls(), 'The second call is a cache hit.');
    }

    public function testAProviderThatInventsANumberGetsTheTemplate(): void
    {
        $this->generator->script(['You have 987654 invoices and 42 customers.']);

        $result = $this->summarize();

        self::assertTrue($result['fallback']);
        self::assertSame($this->template(), $result['text']);
        self::assertStringNotContainsString('987654', $result['text']);
    }

    public function testAProviderOutageGetsTheTemplate(): void
    {
        $this->generator->script([AiUnavailableException::create(AiUnavailableException::REASON_TIMEOUT)]);

        $result = $this->summarize();

        self::assertTrue($result['fallback']);
        self::assertSame($this->template(), $result['text']);
    }

    public function testABudgetRefusalReachesTheCaller(): void
    {
        $this->generator->script([AiBudgetExceededException::create()]);

        $exception = $this->refusal();
        self::assertSame('ai_budget_exceeded', $exception->getErrorCode());
        self::assertSame(402, $exception->getStatusCode());
    }

    public function testARateLimitRefusalReachesTheCaller(): void
    {
        $this->generator->script([AiRateLimitedException::create(30)]);

        $exception = $this->refusal();
        self::assertSame('ai_rate_limited', $exception->getErrorCode());
        self::assertSame(429, $exception->getStatusCode());
        self::assertArrayHasKey('Retry-After', $exception->getHeaders());
    }

    public function testAnOrganizationWithAiOffNeverReachesTheGenerator(): void
    {
        $this->settings->update(false, 1000000);
        $this->entityManager->flush();
        $this->generator->script(['This workspace has 1 customer and 1 invoice.']);

        $exception = $this->refusal();
        self::assertSame('ai_disabled', $exception->getErrorCode());
        self::assertSame(403, $exception->getStatusCode());
        self::assertSame(0, $this->generator->calls());
    }

    public function testTheGlobalSwitchNeverReachesTheGenerator(): void
    {
        $this->flags->overrideGlobalEnabledForTests(false);
        $this->generator->script(['This workspace has 1 customer and 1 invoice.']);

        $exception = $this->refusal();
        self::assertSame('ai_disabled', $exception->getErrorCode());
        self::assertSame(0, $this->generator->calls());
    }

    public function testAForeignCallerGetsNotFoundBeforeAnyRead(): void
    {
        $stranger = new Principal('summary-service-stranger-'.bin2hex(random_bytes(6)).'@example.test', 'Stranger');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        try {
            $this->service->summarize($stranger, $this->organization->getId(), SummaryKind::Overview, 'en');
            self::fail('A non-member must not receive facts.');
        } catch (ApiException $exception) {
            self::assertSame('not_found', $exception->getErrorCode());
        } finally {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM principals WHERE id = ?', [$stranger->getId()]);
        }
    }

    /** @return array{text: string, generatedAt: string, fallback: bool, facts: array<string, mixed>} */
    private function summarize(): array
    {
        return $this->service->summarize($this->principal, $this->organization->getId(), SummaryKind::Overview, 'en');
    }

    private function template(): string
    {
        return (new SummaryFallback())->text(
            $this->facts->build($this->organization->getId(), $this->principal->getId(), SummaryKind::Overview, 'en'),
        );
    }

    private function refusal(): ApiException
    {
        try {
            $this->summarize();
            self::fail('The service should have refused this summary.');
        } catch (ApiException $exception) {
            return $exception;
        }
    }
}
