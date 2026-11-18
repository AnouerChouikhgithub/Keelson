<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for the draft edit/delete race: both handlers must take a
 * row lock before reading status. The lock-contention test holds an explicit
 * FOR UPDATE lock on a second connection and asserts the request blocks on it,
 * which a plain (unlocked) read would not.
 */
final class InvoiceDraftConcurrencyTest extends WebTestCase
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
        $this->principal = new Principal('race-'.$this->suffix.'@example.test');
        $this->organization = new Organization('Race Tenant '.$this->suffix, 'race-'.$this->suffix);
        $this->entityManager->persist($this->principal);
        $this->entityManager->persist($this->organization);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->customer = new Customer($this->organization, 'Race Customer '.$this->suffix);
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

    public function testDraftEditSucceedsWhenUncontended(): void
    {
        $invoice = $this->draftInvoice();
        $this->client->request('PATCH', $this->invoicePath($invoice), server: $this->jsonServer(), content: json_encode([
            'lineItems' => [['description' => 'Updated work', 'quantity' => '1.000', 'unitPrice' => '20.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame('20.000', $this->column($invoice, 'subtotal'));
    }

    public function testEditAfterSendIsRejectedAndLeavesInvoiceSent(): void
    {
        $invoice = $this->draftInvoice();
        $invoice->send();
        $this->entityManager->flush();

        $this->client->request('PATCH', $this->invoicePath($invoice), server: $this->jsonServer(), content: json_encode([
            'lineItems' => [['description' => 'Late edit', 'quantity' => '9.000', 'unitPrice' => '99.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('sent', $this->column($invoice, 'status'));
        self::assertSame('10.000', $this->column($invoice, 'subtotal'));
    }

    public function testDeleteAfterSendIsRejectedAndKeepsInvoice(): void
    {
        $invoice = $this->draftInvoice();
        $invoice->send();
        $this->entityManager->flush();

        $this->client->request('DELETE', $this->invoicePath($invoice), server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM invoices WHERE id = ?',
            [$this->id($invoice)],
        ));
    }

    public function testSendAndMarkPaidEndpointsCompleteTheLockedTransition(): void
    {
        $invoice = $this->draftInvoice();

        $this->client->request('POST', $this->invoicePath($invoice).'/send', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);
        self::assertResponseIsSuccessful();
        self::assertSame('sent', json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['invoice']['status']);

        $this->client->request('POST', $this->invoicePath($invoice).'/mark-paid', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);
        self::assertResponseIsSuccessful();
        self::assertSame('paid', json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['invoice']['status']);
        self::assertSame('paid', $this->column($invoice, 'status'));

        // Retrying completed transitions is a no-op and must not append a
        // duplicate status event.
        $this->client->request('POST', $this->invoicePath($invoice).'/send', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $this->invoicePath($invoice).'/mark-paid', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND invoice_id = ? AND action = 'invoice.sent'",
            [$this->id($this->organization), $this->id($invoice)],
        ));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM invoice_audit_events WHERE organization_id = ? AND invoice_id = ? AND action = 'invoice.paid'",
            [$this->id($this->organization), $this->id($invoice)],
        ));
    }

    public function testDraftEditWaitsForAConcurrentRowLock(): void
    {
        $invoice = $this->draftInvoice();
        $connection = $this->entityManager->getConnection();
        $other = DriverManager::getConnection($connection->getParams());
        $other->beginTransaction();
        $other->executeStatement('SELECT id FROM invoices WHERE id = ? FOR UPDATE', [$this->id($invoice)]);
        $connection->executeStatement("SET lock_timeout = '300ms'");
        $this->client->catchExceptions(false);

        $statusAfter = null;
        $subtotalAfter = null;
        $invoiceId = $this->id($invoice);
        try {
            $this->client->request('PATCH', $this->invoicePath($invoice), server: $this->jsonServer(), content: json_encode([
                'lineItems' => [['description' => 'Blocked edit', 'quantity' => '1.000', 'unitPrice' => '11.000']],
            ], JSON_THROW_ON_ERROR));
            self::fail('The draft edit should wait on the concurrent FOR UPDATE lock, not read a stale draft.');
        } catch (\Throwable $exception) {
            // Expected: the handler requested the row lock and hit the lock timeout.
            // DBAL 4 may rethrow the driver's PDO exception here, so accept both shapes.
            self::assertTrue(
                $exception instanceof LockWaitTimeoutException
                    || str_contains($exception->getMessage(), '55P03')
                    || str_contains($exception->getMessage(), 'lock timeout'),
                sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
            );
        } finally {
            // `catchExceptions(false)` skips the kernel's exception listeners, so
            // the failed edit left its transaction open and aborted; tearDown
            // would then collide with it.
            $main = $this->entityManager->getConnection();
            if ($main->isTransactionActive()) {
                $main->rollBack();
            }
            $other->rollBack();
            $statusAfter = (string) $other->fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]);
            $subtotalAfter = (string) $other->fetchOne('SELECT subtotal FROM invoices WHERE id = ?', [$invoiceId]);
            $other->close();
        }

        self::assertSame('draft', $statusAfter);
        self::assertSame('10.000', $subtotalAfter);
    }

    public function testRejectedDraftEditLeavesACallerOwnedTransactionOpen(): void
    {
        $invoice = $this->draftInvoice();
        $invoice->send();
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // An outer unit of work owns this transaction; the handler must not resolve it.
            $connection->executeStatement('UPDATE customers SET name = ? WHERE id = ?', ['Outer marker', $this->id($this->customer)]);
            $this->client->request('PATCH', $this->invoicePath($invoice), server: $this->jsonServer(), content: json_encode([
                'lineItems' => [['description' => 'Late edit', 'quantity' => '9.000', 'unitPrice' => '99.000']],
            ], JSON_THROW_ON_ERROR));

            self::assertResponseStatusCodeSame(409);
            self::assertTrue($connection->isTransactionActive(), 'The rejected edit resolved a transaction it did not open.');
            self::assertSame('Outer marker', (string) $connection->fetchOne(
                'SELECT name FROM customers WHERE id = ?',
                [$this->id($this->customer)],
            ));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testIdempotentSendLeavesACallerOwnedTransactionOpen(): void
    {
        $invoice = $this->draftInvoice();
        $invoice->send();
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE customers SET name = ? WHERE id = ?', ['Outer marker', $this->id($this->customer)]);
            $this->client->request('POST', $this->invoicePath($invoice).'/send', server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)]);

            self::assertResponseIsSuccessful();
            self::assertTrue($connection->isTransactionActive(), 'The idempotent send committed a transaction it did not open.');
            self::assertSame('Outer marker', (string) $connection->fetchOne(
                'SELECT name FROM customers WHERE id = ?',
                [$this->id($this->customer)],
            ));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private function draftInvoice(): Invoice
    {
        $invoice = new Invoice(
            $this->organization,
            $this->customer,
            '2026-9'.substr($this->suffix, 0, 5),
            $this->id($this->principal),
            (new \DateTimeImmutable('today'))->modify('-1 day'),
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

    /** @return array<string, string> */
    private function jsonServer(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client)];
    }

    private function invoicePath(Invoice $invoice): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/invoices/'.$this->id($invoice);
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
