<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for customer deletion.
 *
 * The handler must lock the customer row before checking references: PostgreSQL
 * takes a FOR KEY SHARE lock on the parent for every invoice insert, and FOR
 * UPDATE conflicts with it, so an invoice created concurrently cannot slip in
 * between the existence check and the DELETE. Failures that are not foreign-key
 * violations must never be reported as a 409.
 */
final class CustomerDeletionConcurrencyTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Principal $principal;
    private Organization $organization;
    private Customer $customer;
    private string $customerId;

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
        $this->principal = new Principal($issuer, 'customer-delete-'.$this->suffix);
        $this->organization = new Organization('Delete Tenant '.$this->suffix, 'delete-'.$this->suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->customer = new Customer($this->organization, 'Deletable '.$this->suffix);
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();
        // Capture the id up front: a deleted entity is detached from the unit of
        // work and must not be relied on for identity afterwards.
        $this->customerId = $this->id($this->customer);
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $orgId = $this->id($this->organization);
                $principalId = $this->id($this->principal);
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$orgId]);
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

    public function testDeleteWithInvoicesReturnsConflictAndWritesNoAuditEvent(): void
    {
        $this->draftInvoice();

        $this->client->request('DELETE', $this->customerPath(), server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->rowCount('customers', 'id', $this->customerId));
        self::assertSame(0, $this->countAudit('customer.deleted'));
    }

    public function testDeleteSucceedsAndWritesTheAuditEvent(): void
    {
        $this->client->request('DELETE', $this->customerPath(), server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->rowCount('customers', 'id', $this->customerId));
        self::assertSame(1, $this->countAudit('customer.deleted'));
    }

    public function testConcurrentInvoiceInsertBlocksTheDeleteInsteadOfRacingIt(): void
    {
        $other = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $other->beginTransaction();
        // An uncommitted invoice insert holds FOR KEY SHARE on the customer row.
        $other->executeStatement(
            "INSERT INTO invoices (id, organization_id, customer_id, number, status, customer_snapshot, issue_date, due_date, line_items, tax_rate, discount_amount, subtotal, tax_amount, total, currency, created_by_principal_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'draft', '{}', CURRENT_DATE, CURRENT_DATE, '[]', NULL, NULL, 0, 0, 0, 'TND', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                \App\Util\Uuid::v4(),
                $this->id($this->organization),
                $this->customerId,
                'race-'.$this->suffix,
                $this->id($this->principal),
            ],
        );

        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '300ms'");
        $this->client->catchExceptions(false);

        try {
            $this->client->request('DELETE', $this->customerPath(), server: ['HTTP_ORIGIN' => 'http://localhost']);
            self::fail('The delete should wait on the concurrent invoice insert instead of racing it.');
        } catch (\Throwable $exception) {
            // A lock timeout here proves the handler asks for the row lock. It
            // also proves that a failure which is not a foreign-key violation is
            // not turned into the 409 conflict response.
            self::assertTrue(
                $exception instanceof LockWaitTimeoutException
                    || str_contains($exception->getMessage(), '55P03')
                    || str_contains($exception->getMessage(), 'lock timeout'),
                sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
            );
        } finally {
            $this->entityManager->getConnection()->executeStatement('SET lock_timeout = DEFAULT');
            $other->rollBack();
            $customerId = $this->customerId;
            self::assertSame(1, (int) $other->fetchOne('SELECT COUNT(*) FROM customers WHERE id = ?', [$customerId]));
            self::assertSame(0, (int) $other->fetchOne("SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND action = 'customer.deleted'", [$this->id($this->organization)]));
            $other->close();
        }
    }

    private function draftInvoice(): Invoice
    {
        $invoice = new Invoice(
            $this->organization,
            $this->customer,
            '2026-8'.substr($this->suffix, 0, 5),
            $this->id($this->principal),
            new \DateTimeImmutable('today'),
            (new \DateTimeImmutable('today'))->modify('+14 days'),
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
        );
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function customerPath(): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/customers/'.$this->customerId;
    }

    private function rowCount(string $table, string $column, string $value): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column),
            [$value],
        );
    }

    private function countAudit(string $action): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND action = ?',
            [$this->id($this->organization), $action],
        );
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
