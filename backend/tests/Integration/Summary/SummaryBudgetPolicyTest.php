<?php

declare(strict_types=1);

namespace App\Tests\Integration\Summary;

use App\Ai\AiFeatureFlags;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The budget gate applies to cached summaries, not only to provider calls.
 *
 * The cache exists to save a provider call. It is not an entitlement: a tenant
 * whose month is spent (or whose budget is `0`, the schema default that keeps the
 * feature off until someone configures it) must not keep reading model-generated
 * summaries until the entry expires while the guide answers `402`. These tests pin
 * that rule, the disabled-organization refusal that already covered cache hits,
 * the ledger's silence on a cache hit, and the tenant half of the cache key.
 */
final class SummaryBudgetPolicyTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private const BUDGET = 1000000;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private AiFeatureFlags $flags;
    private string $suffix;
    private Organization $organization;
    private Organization $otherOrganization;
    private Principal $owner;
    private Principal $otherOwner;
    private OrganizationAiSettings $settings;
    private OrganizationAiSettings $otherSettings;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $flags = $container->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Budget Policy '.$this->suffix, 'budget-policy-'.$this->suffix);
        $this->otherOrganization = new Organization('Budget Policy Other '.$this->suffix, 'budget-policy-other-'.$this->suffix);
        $this->owner = new Principal('budget-owner-'.$this->suffix.'@example.test', 'Budget Owner');
        $this->otherOwner = new Principal('budget-other-'.$this->suffix.'@example.test', 'Budget Other Owner');

        foreach ([$this->organization, $this->otherOrganization, $this->owner, $this->otherOwner] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->entityManager->persist(new Membership($this->organization, $this->owner, 'Owner'));
        $this->entityManager->persist(new Membership($this->otherOrganization, $this->otherOwner, 'Owner'));
        $this->settings = new OrganizationAiSettings($this->organization, true, self::BUDGET);
        $this->otherSettings = new OrganizationAiSettings($this->otherOrganization, true, self::BUDGET);
        $this->entityManager->persist($this->settings);
        $this->entityManager->persist($this->otherSettings);
        $this->entityManager->flush();

        $this->seedMatchingFacts($this->organization, $this->owner);
        $this->seedMatchingFacts($this->otherOrganization, $this->otherOwner);
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $organizationIds = [$this->organization->getId(), $this->otherOrganization->getId()];
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id IN (?, ?)', $organizationIds);
            foreach (['organization_ai_settings', 'invoices', 'customers', 'memberships'] as $table) {
                $connection->executeStatement('DELETE FROM '.$table.' WHERE organization_id IN (?, ?)', $organizationIds);
            }
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', $organizationIds);
            $connection->executeStatement(
                'DELETE FROM principals WHERE id IN (?, ?)',
                [$this->owner->getId(), $this->otherOwner->getId()],
            );
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testACacheHitIsRefusedWhenTheBudgetIsExhausted(): void
    {
        $this->summarize($this->owner, $this->organization);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->body()['fallback'] ?? null, 'The first request generates for real.');
        self::assertSame(1, $this->ledgerRows($this->organization));

        // The entry is cached; the tenant then spends what is left of the month.
        // The change is a plain UPDATE because the request detaches the managed
        // entity, and an operator changing a budget does not go through the ORM.
        $this->storeSettings($this->organization, 0, true);
        self::assertSame(0, $this->storedBudget($this->organization), 'The exhausted budget must be the stored one.');

        $this->summarize($this->owner, $this->organization);

        self::assertResponseStatusCodeSame(402);
        self::assertSame('ai_budget_exceeded', $this->body()['error']['code'] ?? null);
        self::assertSame(1, $this->ledgerRows($this->organization), 'A refused cache hit is not a provider attempt.');
    }

    public function testAFreshSummaryWithoutBudgetNeverReachesTheProvider(): void
    {
        $this->storeSettings($this->organization, 0, true);

        $this->summarize($this->owner, $this->organization);

        self::assertResponseStatusCodeSame(402);
        self::assertSame('ai_budget_exceeded', $this->body()['error']['code'] ?? null);
        self::assertSame(0, $this->ledgerRows($this->organization));
    }

    public function testADisabledOrganizationGetsNoCachedAnswer(): void
    {
        $this->summarize($this->owner, $this->organization);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->ledgerRows($this->organization));

        $this->storeSettings($this->organization, self::BUDGET, false);

        $this->summarize($this->owner, $this->organization);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('ai_disabled', $this->body()['error']['code'] ?? null);
        self::assertSame(1, $this->ledgerRows($this->organization));
    }

    /**
     * Identical facts in two tenants. Only the organization id separates them in
     * the cache key, so a leak here would be invisible in the text — the ledger
     * is what proves that the second tenant paid for its own answer.
     */
    public function testOneOrganizationsCachedAnswerIsNeverServedToAnother(): void
    {
        $this->summarize($this->owner, $this->organization);
        self::assertResponseIsSuccessful();
        $first = $this->body();

        $this->summarize($this->otherOwner, $this->otherOrganization);
        self::assertResponseIsSuccessful();
        $second = $this->body();

        self::assertSame(1, $this->ledgerRows($this->organization));
        self::assertSame(1, $this->ledgerRows($this->otherOrganization));
        self::assertSame(['Same Name Ltd'], $first['facts']['customerNames'] ?? null);
        self::assertSame(['Same Name Ltd'], $second['facts']['customerNames'] ?? null);
    }

    private function seedMatchingFacts(Organization $organization, Principal $principal): void
    {
        // The same name, amount and dates on purpose: the two fact sets differ only
        // in the organization they belong to.
        $customer = new Customer($organization, 'Same Name Ltd');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->entityManager->persist(new Invoice(
            $organization,
            $customer,
            'BP-'.$this->suffix,
            $principal->getId(),
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
    }

    private function summarize(Principal $principal, Organization $organization): void
    {
        $this->client->loginUser($principal);
        $this->client->request('POST', '/api/organizations/'.$organization->getId().'/ai/summaries/overview', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ], content: '{}');
    }

    private function storeSettings(Organization $organization, int $budget, bool $enabled): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE organization_ai_settings SET monthly_token_budget = ?, enabled = ?, updated_at = now() WHERE organization_id = ?',
            [$budget, $enabled, $organization->getId()],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING],
        );
    }

    private function storedBudget(Organization $organization): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT monthly_token_budget FROM organization_ai_settings WHERE organization_id = ?',
            [$organization->getId()],
        );
    }

    private function ledgerRows(Organization $organization): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ai_requests WHERE organization_id = ?',
            [$organization->getId()],
        );
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
