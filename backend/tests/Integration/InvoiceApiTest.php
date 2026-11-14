<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Repository\CustomerRepository;
use App\Repository\InvoiceAuditEventRepository;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvoiceApiTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private string $suffix;
    private Principal $principalA;
    private Principal $principalB;
    private Organization $organizationA;
    private Organization $organizationB;
    private Customer $customerA;
    private Customer $customerB;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);
        $this->suffix = bin2hex(random_bytes(6));
        $issuer = 'https://identity.example/invoice-tests/'.$this->suffix;
        $this->principalA = new Principal($issuer, 'principal-a');
        $this->principalB = new Principal($issuer, 'principal-b');
        $this->organizationA = new Organization('Invoice Tenant A '.$this->suffix, 'invoice-a-'.$this->suffix);
        $this->organizationB = new Organization('Invoice Tenant B '.$this->suffix, 'invoice-b-'.$this->suffix);
        foreach ([$this->principalA, $this->principalB, $this->organizationA, $this->organizationB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organizationA, $this->principalA, 'Owner'));
        $this->entityManager->persist(new Membership($this->organizationB, $this->principalB, 'Owner'));
        $this->customerA = new Customer($this->organizationA, 'Customer A '.$this->suffix);
        $this->customerB = new Customer($this->organizationB, 'Customer B '.$this->suffix);
        $this->entityManager->persist($this->customerA);
        $this->entityManager->persist($this->customerB);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            if ($this->hasTable('invoice_audit_events')) {
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
            }
            $connection->executeStatement('DELETE FROM invoices WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
            $connection->executeStatement('DELETE FROM customers WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
            $connection->executeStatement('DELETE FROM memberships WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
            $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
            $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [$this->id($this->principalA), $this->id($this->principalB)]);
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testCustomerAndInvoiceRepositoriesDoNotRevealForeignTenantRows(): void
    {
        $customerRepository = $this->entityManager->getRepository(Customer::class);
        self::assertInstanceOf(CustomerRepository::class, $customerRepository);
        self::assertSame($this->customerA, $customerRepository->findForOrganization($this->id($this->organizationA), $this->id($this->customerA)));
        self::assertNull($customerRepository->findForOrganization($this->id($this->organizationA), $this->id($this->customerB)));
        self::assertSame([], $customerRepository->findForOrganizationList($this->id($this->organizationA), $this->id($this->principalB)));

        $invoice = $this->createInvoice($this->organizationA, $this->customerA, $this->principalA, '2026-000001');
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
        $invoiceRepository = $this->entityManager->getRepository(Invoice::class);
        self::assertInstanceOf(InvoiceRepository::class, $invoiceRepository);
        self::assertSame($invoice, $invoiceRepository->findForOrganization($this->id($this->organizationA), $this->id($invoice), $this->id($this->principalA)));
        self::assertNull($invoiceRepository->findForOrganization($this->id($this->organizationB), $this->id($invoice), $this->id($this->principalB)));
        self::assertNull($invoiceRepository->findForOrganization($this->id($this->organizationA), $this->id($invoice), $this->id($this->principalB)));
        self::assertSame([], $invoiceRepository->findForOrganizationList($this->id($this->organizationB), $this->id($this->principalB)));
    }

    public function testInvoiceRejectsCrossTenantCustomerAtDatabaseLevel(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            "INSERT INTO invoices (id, organization_id, customer_id, number, status, customer_snapshot, issue_date, due_date, line_items, tax_rate, discount_amount, subtotal, tax_amount, total, currency, created_by_principal_id, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', '{}', CURRENT_DATE, CURRENT_DATE, '[]', NULL, NULL, 0, 0, 0, 'TND', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [\App\Util\Uuid::v4(), $this->id($this->organizationA), $this->id($this->customerB), 'forbidden-'.$this->suffix, $this->id($this->principalA)],
        );
    }

    public function testCustomerDeleteConflictAndAuditEventsAreTenantScopedAndAppendOnly(): void
    {
        $invoice = $this->createInvoice($this->organizationA, $this->customerA, $this->principalA, '2026-000002');
        $this->entityManager->persist($invoice);
        $eventA = new InvoiceAuditEvent($this->organizationA, null, $this->id($this->customerA), 'customer.created', $this->id($this->principalA));
        $eventB = new InvoiceAuditEvent($this->organizationB, null, $this->id($this->customerB), 'customer.created', $this->id($this->principalB));
        $this->entityManager->persist($eventA);
        $this->entityManager->persist($eventB);
        $this->entityManager->flush();

        self::assertTrue($this->entityManager->getRepository(Customer::class)->hasInvoices($this->id($this->organizationA), $this->id($this->customerA)));
        self::assertFalse($this->entityManager->getRepository(Customer::class)->hasInvoices($this->id($this->organizationB), $this->id($this->customerA)));
        $events = $this->entityManager->getRepository(InvoiceAuditEvent::class);
        self::assertInstanceOf(InvoiceAuditEventRepository::class, $events);
        self::assertSame([$eventA], $events->findForOrganization($this->id($this->organizationA), $this->id($this->principalA)));
        self::assertSame([], $events->findForOrganization($this->id($this->organizationA), $this->id($this->principalB)));

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM customers WHERE id = ?', [$this->id($this->customerA)]);
    }

    private function createInvoice(Organization $organization, Customer $customer, Principal $principal, string $number): Invoice
    {
        return new Invoice($organization, $customer, $number, $this->id($principal), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-15'), [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']], null, null, '10.000', '0.000', '10.000');
    }

    private function hasTable(string $table): bool
    {
        return $this->entityManager->getConnection()->createSchemaManager()->tablesExist([$table]);
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
