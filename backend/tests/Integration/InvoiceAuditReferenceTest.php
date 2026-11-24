<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\InvoiceAuditAction;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The audit list carries what a reader needs, not only ids.
 *
 * The screen used to print eight characters of a UUID for the invoice and the
 * customer. The endpoint now resolves both for the page it returns — in two
 * queries, not one per row — and leaves them null when the referenced row is
 * gone, because a deleted invoice keeps its event and its id is all that is left.
 */
final class InvoiceAuditReferenceTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Principal $principal;
    private Customer $customer;
    private Invoice $invoice;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Audit Tenant '.$this->suffix, 'audit-'.$this->suffix);
        $this->principal = new Principal('audit-'.$this->suffix.'@example.test', 'Auditor');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->flush();

        $now = new \DateTimeImmutable('2026-11-20T09:00:00+00:00');
        $this->customer = new Customer(
            $this->organization,
            'Atlas Reference '.$this->suffix,
            'atlas-'.$this->suffix.'@example.test',
            null,
            null,
            $now,
        );
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();

        $this->invoice = new Invoice(
            $this->organization,
            $this->customer,
            'REF-'.strtoupper($this->suffix),
            $this->principal->getId(),
            $now,
            $now,
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
            $now,
        );
        $this->entityManager->persist($this->invoice);
        $this->entityManager->flush();

        $this->entityManager->persist(new InvoiceAuditEvent(
            $this->organization,
            $this->invoice->getId(),
            $this->customer->getId(),
            InvoiceAuditAction::INVOICE_SENT,
            $this->principal->getId(),
            [],
            $now,
        ));
        // An event whose invoice no longer exists: the number cannot be resolved.
        $this->entityManager->persist(new InvoiceAuditEvent(
            $this->organization,
            '00000000-0000-4000-8000-000000000000',
            null,
            InvoiceAuditAction::INVOICE_DELETED,
            $this->principal->getId(),
            [],
            $now->modify('+1 hour'),
        ));
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id = ?', [$this->organization->getId()]);
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$this->organization->getId()]);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$this->organization->getId()]);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$this->organization->getId()]);
                    $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheListCarriesTheInvoiceNumberAndTheCustomerName(): void
    {
        $items = $this->listEvents();

        $sent = $this->itemWithAction($items, InvoiceAuditAction::INVOICE_SENT);
        self::assertSame($this->invoice->getNumber(), $sent['invoiceNumber']);
        self::assertSame($this->customer->getName(), $sent['customerName']);
        // The ids are still there for anything that needs to link to the record.
        self::assertSame($this->invoice->getId(), $sent['invoiceId']);
        self::assertSame($this->customer->getId(), $sent['customerId']);
    }

    public function testAMissingInvoiceOnlyLeavesItsId(): void
    {
        $items = $this->listEvents();

        $deleted = $this->itemWithAction($items, InvoiceAuditAction::INVOICE_DELETED);
        self::assertNull($deleted['invoiceNumber'], 'A deleted invoice has no number to resolve.');
        self::assertNull($deleted['customerName']);
        self::assertSame('00000000-0000-4000-8000-000000000000', $deleted['invoiceId']);
    }

    /** @return list<array<string, mixed>> */
    private function listEvents(): array
    {
        $this->client->request('GET', '/api/organizations/'.$this->organization->getId().'/invoice-audit-events');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['items'] ?? null);

        $items = [];
        foreach ($payload['items'] as $item) {
            self::assertIsArray($item);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function itemWithAction(array $items, string $action): array
    {
        foreach ($items as $item) {
            if ($action === ($item['action'] ?? null)) {
                return $item;
            }
        }

        self::fail(sprintf('No audit event with action "%s" in the page.', $action));
    }
}
