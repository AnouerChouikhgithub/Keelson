<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Business rows and their audit events share one transaction and one flush.
 *
 * The test injects a failing audit constraint, so the audit INSERT is rejected
 * by PostgreSQL after the business row was scheduled. The whole unit of work
 * must roll back: no customer/invoice, no audit event.
 */
final class AuditAtomicityTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $raw;
    private KernelBrowser $client;
    private string $suffix;
    private Principal $principal;
    private Organization $organization;
    private Customer $customer;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $this->raw = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $this->suffix = bin2hex(random_bytes(6));
        $issuer = $_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? null;
        self::assertNotNull($issuer, 'OIDC_ISSUER must be configured for the HTTP test user provider.');
        $this->principal = new Principal($issuer, 'atomic-'.$this->suffix);
        $this->organization = new Organization('Atomic Tenant '.$this->suffix, 'atomic-'.$this->suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->customer = new Customer($this->organization, 'Atomic Customer '.$this->suffix);
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->raw)) {
                $this->raw->executeStatement('ALTER TABLE invoice_audit_events DROP CONSTRAINT IF EXISTS chk_test_block_audit_action');
                $this->raw->close();
            }
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

    public function testFailedCustomerAuditInsertRollsBackTheCustomer(): void
    {
        $this->blockAuditActions(['customer.created']);
        $name = 'Never persisted '.$this->suffix;

        $this->client->request('POST', $this->customersPath(), server: $this->jsonServer(), content: json_encode(['name' => $name], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT COUNT(*) FROM customers WHERE organization_id = ? AND name = ?', [$this->id($this->organization), $name]));
        self::assertSame(0, $this->auditCount('customer.created'));
    }

    public function testFailedInvoiceAuditInsertRollsBackTheInvoice(): void
    {
        $this->blockAuditActions(['invoice.created']);

        $this->client->request('POST', $this->invoicesPath(), server: $this->jsonServer(), content: json_encode([
            'customerId' => $this->id($this->customer),
            'issueDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'currency' => 'TND',
            'lineItems' => [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT COUNT(*) FROM invoices WHERE organization_id = ?', [$this->id($this->organization)]));
        self::assertSame(0, $this->auditCount('invoice.created'));
    }

    /** @param list<string> $actions */
    private function blockAuditActions(array $actions): void
    {
        $quoted = implode(', ', array_map(static fn (string $action): string => "'".$action."'", $actions));
        $this->raw->executeStatement(sprintf(
            'ALTER TABLE invoice_audit_events ADD CONSTRAINT chk_test_block_audit_action CHECK (action NOT IN (%s))',
            $quoted,
        ));
    }

    private function auditCount(string $action): int
    {
        return (int) $this->raw->fetchOne(
            'SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND action = ?',
            [$this->id($this->organization), $action],
        );
    }

    private function customersPath(): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/customers';
    }

    private function invoicesPath(): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/invoices';
    }

    /** @return array<string, string> */
    private function jsonServer(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'];
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
