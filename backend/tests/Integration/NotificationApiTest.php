<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Service\InvoiceOverdueService;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Notifications are per recipient: the inbox is scoped by the session, other
 * people's ids are 404, and the automatic creators (payment, overdue run) reach
 * the administration without duplicating themselves.
 */
final class NotificationApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Customer $customer;

    /** @var array<string, Principal> */
    private array $principals = [];

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Notify Tenant '.$this->suffix, 'notify-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        foreach (['Owner', 'Admin', 'Member', 'Stranger'] as $role) {
            $principal = new Principal('notify-'.strtolower($role).'-'.$this->suffix.'@example.test', $role);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->customer = new Customer($this->organization, 'Notify Customer');
        $this->entityManager->persist($this->customer);
        $this->entityManager->flush();
        foreach (['Owner', 'Admin', 'Member'] as $role) {
            $this->entityManager->persist(new Membership($this->organization, $this->principals[$role], $role));
        }
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['notify-%']);
                    $connection->executeStatement('DELETE FROM notifications WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['notify-%']);
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['notify-%']);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['notify-%']);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE ?)', ['notify-%']);
                    $connection->executeStatement("DELETE FROM organizations WHERE subdomain LIKE 'notify-%'");
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                foreach ($this->principals as $principal) {
                    $connection->executeStatement('DELETE FROM notifications WHERE principal_id = ?', [$principal->getId()]);
                    $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principal->getId()]);
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheInboxIsScopedToTheCaller(): void
    {
        $mine = $this->notify($this->principals['Owner'], 'read one');
        $unread = $this->notify($this->principals['Owner'], 'unread one');
        $theirs = $this->notify($this->principals['Stranger'], 'not yours');
        $this->entityManager->flush();
        $mine->markRead();
        $this->entityManager->flush();

        $this->client->loginUser($this->principals['Owner']);
        $this->client->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        $items = $this->payload()['items'];
        self::assertCount(2, $items);
        self::assertEqualsCanonicalizing([$mine->getId(), $unread->getId()], array_column($items, 'id'));
        self::assertSame(['id', 'type', 'data', 'organizationId', 'read', 'createdAt', 'readAt'], array_keys($items[0]));

        $this->client->request('GET', '/api/notifications?unread=1');
        self::assertCount(1, $this->payload()['items']);
        self::assertFalse($this->payload()['items'][0]['read']);

        // Someone else's id is indistinguishable from a missing one.
        $this->send('POST', '/api/notifications/'.$theirs->getId().'/read');
        self::assertResponseStatusCodeSame(404);

        $this->send('POST', '/api/notifications/'.$unread->getId().'/read');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['notification']['read']);

        $this->send('POST', '/api/notifications/read-all');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['updated']);

        $this->client->request('GET', '/api/notifications?unread=1');
        self::assertCount(0, $this->payload()['items']);

        $this->client->request('GET', '/api/notifications?cursor=not-a-cursor');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->payload()['error']['code']);
    }

    public function testRecordingAPaymentNotifiesTheRestOfTheAdministrationOnce(): void
    {
        $invoice = $this->invoice('sent', new \DateTimeImmutable('today'), new \DateTimeImmutable('+10 days'));
        $this->entityManager->flush();

        $this->client->loginUser($this->principals['Owner']);
        $this->send('POST', '/api/organizations/'.$this->organization->getId().'/invoices/'.$invoice->getId().'/mark-paid');
        self::assertResponseIsSuccessful();

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT principal_id FROM notifications WHERE organization_id = ? AND type = 'invoice_paid'",
            [$this->organization->getId()],
        );
        $recipients = array_column($rows, 'principal_id');
        self::assertContains($this->principals['Admin']->getId(), $recipients);
        self::assertNotContains($this->principals['Owner']->getId(), $recipients, 'the actor is not notified of their own action');
        self::assertNotContains($this->principals['Member']->getId(), $recipients);
        self::assertCount(1, $recipients, 'one notice per recipient');
    }

    public function testTheOverdueRunNotifiesOnceAndIsSafeToRepeat(): void
    {
        $invoice = $this->invoice('sent', new \DateTimeImmutable('-10 days'), new \DateTimeImmutable('-2 days'));
        $this->entityManager->flush();

        $service = self::getContainer()->get(InvoiceOverdueService::class);
        self::assertGreaterThanOrEqual(1, $service->markOverdue());

        $count = function (): int {
            return (int) $this->entityManager->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM notifications WHERE organization_id = ? AND type = 'invoice_overdue'",
                [$this->organization->getId()],
            );
        };
        $first = $count();
        self::assertSame(2, $first, 'Owner and Admin, not the Member');

        $this->entityManager->clear();
        self::assertSame(0, $service->markOverdue());
        self::assertSame($first, $count());
        self::assertSame('overdue', $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM invoices WHERE id = ?',
            [$invoice->getId()],
        ));
    }

    private function notify(Principal $principal, string $label): Notification
    {
        $notification = new Notification($principal, $this->organization, Notification::TYPE_MEMBER_JOINED, ['label' => $label]);
        $this->entityManager->persist($notification);

        return $notification;
    }

    private function invoice(string $status, \DateTimeImmutable $issueDate, \DateTimeImmutable $dueDate): Invoice
    {
        $invoice = new Invoice(
            $this->organization,
            $this->customer,
            'N-'.substr($this->suffix, 0, 6).'-'.bin2hex(random_bytes(2)),
            $this->principals['Owner']->getId(),
            $issueDate,
            $dueDate,
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '12.000', 'lineTotal' => '12.000']],
            null,
            null,
            '12.000',
            '0.000',
            '12.000',
        );
        if ('sent' === $status) {
            $invoice->send();
        }
        $this->entityManager->persist($invoice);

        return $invoice;
    }

    private function send(string $method, string $path): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
