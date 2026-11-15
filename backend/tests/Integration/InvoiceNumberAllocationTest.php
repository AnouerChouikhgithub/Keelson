<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invoice number allocation under concurrency.
 *
 * Numbers stay unique per organization through the unique index, and concurrent
 * creates are serialized by a transaction-scoped advisory lock instead of
 * colliding or skipping numbers.
 */
final class InvoiceNumberAllocationTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Principal $principal;
    private Organization $organization;
    private Organization $otherOrganization;
    private Customer $customer;
    private Customer $otherCustomer;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $this->suffix = bin2hex(random_bytes(6));
        $issuer = $_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? null;
        self::assertNotNull($issuer, 'OIDC_ISSUER must be configured for the HTTP test user provider.');
        $this->principal = new Principal($issuer, 'numbering-'.$this->suffix);
        $this->organization = new Organization('Numbering Tenant '.$this->suffix, 'numbering-'.$this->suffix);
        $this->otherOrganization = new Organization('Numbering Other '.$this->suffix, 'numbering-b-'.$this->suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->otherOrganization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->persist(new Membership($this->otherOrganization, $this->principal, 'Owner'));
        $this->customer = new Customer($this->organization, 'Numbering Customer '.$this->suffix);
        $this->otherCustomer = new Customer($this->otherOrganization, 'Other Customer '.$this->suffix);
        $this->entityManager->persist($this->customer);
        $this->entityManager->persist($this->otherCustomer);
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $orgIds = [$this->id($this->organization), $this->id($this->otherOrganization)];
                $principalId = $this->id($this->principal);
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    foreach ($orgIds as $orgId) {
                        $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id = ?', [$orgId]);
                        $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$orgId]);
                        $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$orgId]);
                        $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$orgId]);
                        $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$orgId]);
                    }
                    $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principalId]);
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testSequentialCreatesAllocateConsecutiveUniqueNumbers(): void
    {
        $first = $this->createInvoice($this->organization, $this->customer);
        $second = $this->createInvoice($this->organization, $this->customer);

        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $first);
        self::assertMatchesRegularExpression('/^\d{4}-\d{6}$/', $second);
        self::assertNotSame($first, $second);
        self::assertSame(
            (int) substr($first, -6) + 1,
            (int) substr($second, -6),
            'Consecutive creations should not skip or duplicate a number.',
        );
    }

    public function testNumbersAreAllocatedPerOrganization(): void
    {
        $first = $this->createInvoice($this->organization, $this->customer);
        $other = $this->createInvoice($this->otherOrganization, $this->otherCustomer);

        self::assertSame('000001', substr($first, -6));
        self::assertSame('000001', substr($other, -6), 'Another tenant may use the same number.');
    }

    public function testConcurrentAllocationBlocksAndRollsBackOnTimeout(): void
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $other = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $other->beginTransaction();
        // Hold the same per-tenant/year allocation lock a concurrent create would take.
        $other->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [
            sprintf('invoice-number:%s:%s', $this->id($this->organization), $year),
        ]);

        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '300ms'");
        $this->client->catchExceptions(false);

        try {
            $this->client->request('POST', $this->invoicesPath($this->organization), server: $this->jsonServer(), content: $this->payload($this->customer));
            self::fail('The create should wait for the per-tenant number allocation lock.');
        } catch (\Throwable $exception) {
            self::assertTrue(
                $exception instanceof LockWaitTimeoutException
                    || str_contains($exception->getMessage(), '55P03')
                    || str_contains($exception->getMessage(), 'lock timeout'),
                sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
            );
        } finally {
            $this->entityManager->getConnection()->executeStatement('SET lock_timeout = DEFAULT');
            $other->rollBack();
        }

        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoices WHERE organization_id = ?',
            [$this->id($this->organization)],
        ), 'The failed allocation must not leave a partially created invoice.');
        $other->close();
    }

    private function createInvoice(Organization $organization, Customer $customer): string
    {
        $this->client->request('POST', $this->invoicesPath($organization), server: $this->jsonServer(), content: $this->payload($customer));
        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return (string) $body['invoice']['number'];
    }

    private function payload(Customer $customer): string
    {
        return json_encode([
            'customerId' => $this->id($customer),
            'issueDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'currency' => 'TND',
            'lineItems' => [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000']],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function jsonServer(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'];
    }

    private function invoicesPath(Organization $organization): string
    {
        return '/api/organizations/'.$this->id($organization).'/invoices';
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
