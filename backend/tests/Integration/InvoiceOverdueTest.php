<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Service\InvoiceOverdueService;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Overdue behaviour after removing writes from GET requests.
 *
 * Reads derive overdue from the due date; a separate, explicit operation records
 * the transition with a row lock and one audit event per real transition, so a
 * concurrent payment is never overwritten.
 */
final class InvoiceOverdueTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
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
        $this->suffix = bin2hex(random_bytes(6));
        $this->principal = new Principal('overdue-'.$this->suffix.'@example.test');
        $this->organization = new Organization('Overdue Tenant '.$this->suffix, 'overdue-'.$this->suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->customer = new Customer($this->organization, 'Overdue Customer '.$this->suffix);
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();
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

    public function testReadingAPastDueInvoiceDerivesOverdueWithoutWriting(): void
    {
        $invoice = $this->invoice('sent', '-1 day');

        $this->client->request('GET', $this->invoicePath($invoice));
        self::assertResponseIsSuccessful();
        self::assertSame('overdue', $this->json()['invoice']['status']);
        self::assertSame(0, $this->auditCount('invoice.overdue'), 'A GET must not create an overdue audit event.');
        self::assertSame('sent', $this->column($invoice, 'status'), 'A GET must not persist the derived status.');

        // Repeated reads stay read-only and stable.
        $this->client->request('GET', $this->invoicePath($invoice));
        self::assertResponseIsSuccessful();
        self::assertSame('overdue', $this->json()['invoice']['status']);
        self::assertSame(0, $this->auditCount('invoice.overdue'));
        self::assertSame('sent', $this->column($invoice, 'status'));
    }

    public function testSentInvoiceBeforeItsDueDateIsNotOverdueAndFiltersStayConsistent(): void
    {
        $future = $this->invoice('sent', '+5 days');

        $this->client->request('GET', $this->invoicePath($future));
        self::assertResponseIsSuccessful();
        self::assertSame('sent', $this->json()['invoice']['status']);

        $this->client->request('GET', $this->invoiceListPath(['status' => 'sent']));
        self::assertSame([$this->id($future)], $this->ids($this->json()['items']));

        $this->client->request('GET', $this->invoiceListPath(['status' => 'overdue']));
        self::assertSame([], $this->ids($this->json()['items']));
    }

    public function testOverdueFilterPaginatesAcrossPagesWithoutDuplicates(): void
    {
        $first = $this->invoice('sent', '-3 days');
        $second = $this->invoice('sent', '-2 days');
        $third = $this->invoice('sent', '-1 day');
        $future = $this->invoice('sent', '+3 days');

        $this->client->request('GET', $this->invoiceListPath(['status' => 'overdue', 'limit' => 2]));
        self::assertResponseIsSuccessful();
        $page = $this->json();
        self::assertCount(2, $page['items']);
        self::assertIsString($page['nextCursor']);
        $seen = $this->ids($page['items']);

        $this->client->request('GET', $this->invoiceListPath(['status' => 'overdue', 'limit' => 2, 'cursor' => $page['nextCursor']]));
        self::assertResponseIsSuccessful();
        $lastPage = $this->json();
        self::assertCount(1, $lastPage['items']);
        self::assertNull($lastPage['nextCursor']);
        $seen = array_merge($seen, $this->ids($lastPage['items']));

        sort($seen);
        $expected = [$this->id($first), $this->id($second), $this->id($third)];
        sort($expected);
        self::assertSame($expected, $seen);
        self::assertNotContains($this->id($future), $seen);
        self::assertSame(0, $this->auditCount('invoice.overdue'), 'Reading the overdue filter must not write.');
        foreach ([$first, $second, $third] as $invoice) {
            self::assertSame('sent', $this->column($invoice, 'status'));
        }
    }

    public function testOverdueInvoiceCanBeMarkedPaid(): void
    {
        $invoice = $this->invoice('sent', '-2 days');

        $this->client->request('POST', $this->invoicePath($invoice).'/mark-paid', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);

        self::assertResponseIsSuccessful();
        self::assertSame('paid', $this->json()['invoice']['status']);
        self::assertSame('paid', $this->column($invoice, 'status'));
        self::assertSame(1, $this->auditCount('invoice.paid'));
    }

    public function testExplicitOverdueTransitionIsAtomicAuditedAndIdempotent(): void
    {
        $overdue = $this->invoice('sent', '-1 day');
        $future = $this->invoice('sent', '+10 days');
        $paid = $this->invoice('paid', '-5 days');
        $service = self::getContainer()->get(InvoiceOverdueService::class);

        self::assertSame(1, $service->markOverdue(new \DateTimeImmutable('today')));
        self::assertSame('overdue', $this->column($overdue, 'status'));
        self::assertSame('sent', $this->column($future, 'status'), 'A future due date must not transition.');
        self::assertSame('paid', $this->column($paid, 'status'), 'A paid invoice must not transition.');

        $events = $this->entityManager->getRepository(InvoiceAuditEvent::class)->findBy(['invoiceId' => $this->id($overdue)]);
        self::assertCount(1, $events);
        self::assertSame('invoice.overdue', $events[0]->getAction());
        self::assertSame(['trigger' => 'automatic'], $events[0]->getMetadata());

        // A second run changes nothing and writes no duplicate audit event.
        $this->entityManager->clear();
        self::assertSame(0, $service->markOverdue(new \DateTimeImmutable('today')));
        self::assertSame(1, $this->auditCount('invoice.overdue'));
    }

    public function testOverdueTransitionWaitsForAConcurrentPayment(): void
    {
        $invoice = $this->invoice('sent', '-1 day');
        $service = self::getContainer()->get(InvoiceOverdueService::class);

        $other = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        $other->beginTransaction();
        // A concurrent payment holds the row lock without committing yet.
        $other->executeStatement("UPDATE invoices SET status = 'paid' WHERE id = ?", [$this->id($invoice)]);

        $this->entityManager->getConnection()->executeStatement("SET lock_timeout = '300ms'");
        try {
            $service->markOverdue(new \DateTimeImmutable('today'));
            self::fail('The overdue transition should wait for the concurrent payment lock.');
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

        self::assertSame('sent', $this->column($invoice, 'status'));
        self::assertSame(0, $this->auditCount('invoice.overdue'));

        // Once the payment commits first, the transition skips that invoice.
        $other->executeStatement("UPDATE invoices SET status = 'paid' WHERE id = ?", [$this->id($invoice)]);
        $invoiceId = $this->id($invoice);
        $this->entityManager->clear();
        self::assertSame(0, $service->markOverdue(new \DateTimeImmutable('today')));
        self::assertSame('paid', (string) $other->fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(0, $this->auditCount('invoice.overdue'));
        $other->close();
    }

    private function invoice(string $status, string $dueOffset): Invoice
    {
        $issue = (new \DateTimeImmutable('today'))->modify('-30 days');
        $invoice = new Invoice(
            $this->organization,
            $this->customer,
            $this->nextNumber(),
            $this->id($this->principal),
            $issue,
            (new \DateTimeImmutable('today'))->modify($dueOffset),
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
        );
        if (in_array($status, ['sent', 'paid', 'overdue'], true)) {
            $invoice->send();
        }
        if ('paid' === $status) {
            $invoice->markPaid();
        }
        if ('overdue' === $status) {
            $invoice->markOverdue();
        }
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function nextNumber(): string
    {
        static $sequence = 0;

        return sprintf('2026-9%05d', ++$sequence);
    }

    /** @param array<string, string|int> $query */
    private function invoiceListPath(array $query): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/invoices?'.http_build_query($query);
    }

    private function invoicePath(Invoice $invoice): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/invoices/'.$this->id($invoice);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $items */
    private function ids(array $items): array
    {
        return array_map(static fn (array $item): string => (string) $item['id'], $items);
    }

    private function auditCount(string $action): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND action = ?',
            [$this->id($this->organization), $action],
        );
    }

    private function column(Invoice $invoice, string $column): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            sprintf('SELECT %s FROM invoices WHERE id = ?', $column),
            [$this->id($invoice)],
        );
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
