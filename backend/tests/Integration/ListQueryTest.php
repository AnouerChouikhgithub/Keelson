<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `q` and cursor pagination on customers, invoices and the audit trail.
 *
 * The cursor is opaque and keyset-based, so a page resumes after the last row of
 * the previous one even if rows are created in between; a malformed cursor is a
 * 400 rather than a silent restart at page one.
 */
final class ListQueryTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;
    private Principal $principal;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('List Tenant '.$this->suffix, 'list-'.$this->suffix);
        $this->principal = new Principal('list-'.$this->suffix.'@example.test', 'Lister');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->flush();
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

    public function testCustomersSearchAndPageByCursor(): void
    {
        foreach (['Alpha Trading '.$this->suffix, 'Beta Works '.$this->suffix, 'Gamma Labs '.$this->suffix] as $name) {
            $this->entityManager->persist(new Customer($this->organization, $name, strtolower(str_replace(' ', '.', $name)).'@example.test'));
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);

        $this->client->request('GET', $this->path('customers').'?q='.rawurlencode('beta'));
        self::assertResponseIsSuccessful();
        $items = $this->payload()['items'];
        self::assertCount(1, $items);
        self::assertStringStartsWith('Beta Works', $items[0]['name']);

        $this->client->request('GET', $this->path('customers').'?limit=2');
        $first = $this->payload();
        self::assertCount(2, $first['items']);
        self::assertNotNull($first['nextCursor']);

        $this->client->request('GET', $this->path('customers').'?limit=2&cursor='.rawurlencode($first['nextCursor']));
        $second = $this->payload();
        self::assertCount(1, $second['items']);
        self::assertNull($second['nextCursor']);

        $ids = array_merge(array_column($first['items'], 'id'), array_column($second['items'], 'id'));
        self::assertCount(3, array_unique($ids));

        $this->client->request('GET', $this->path('customers').'?cursor=garbage');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->payload()['error']['code']);
    }

    public function testInvoicesSearchByNumberAndPageByCursor(): void
    {
        $customer = new Customer($this->organization, 'Search Co '.$this->suffix);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        foreach (['AQ-1', 'AQ-2', 'ZZ-9'] as $index => $number) {
            $this->entityManager->persist(new Invoice(
                $this->organization,
                $customer,
                $number.'-'.$this->suffix,
                $this->principal->getId(),
                new \DateTimeImmutable(sprintf('-%d days', $index)),
                new \DateTimeImmutable('+5 days'),
                [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '1.000', 'lineTotal' => '1.000']],
                null,
                null,
                '1.000',
                '0.000',
                '1.000',
            ));
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);

        $this->client->request('GET', $this->path('invoices').'?q=AQ-');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['items']);

        $this->client->request('GET', $this->path('invoices').'?limit=100');
        $all = $this->payload();
        self::assertCount(3, $all['items']);
        self::assertNull($all['nextCursor']);

        $this->client->request('GET', $this->path('invoices').'?limit=2');
        $first = $this->payload();
        self::assertCount(2, $first['items']);
        self::assertNotNull($first['nextCursor']);
        $this->client->request('GET', $this->path('invoices').'?limit=2&cursor='.rawurlencode($first['nextCursor']));
        self::assertCount(1, $this->payload()['items']);
    }

    public function testTheAuditTrailSearchesByActionAndPagesByCursor(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->entityManager->persist(new InvoiceAuditEvent(
                $this->organization,
                null,
                null,
                'invoice.updated',
                $this->principal->getId(),
                ['step' => $i],
            ));
        }
        $this->entityManager->persist(new InvoiceAuditEvent($this->organization, null, null, 'customer.created', $this->principal->getId()));
        $this->entityManager->flush();
        $this->client->loginUser($this->principal);

        $this->client->request('GET', $this->path('invoice-audit-events').'?q=customer.');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['items']);

        $this->client->request('GET', $this->path('invoice-audit-events').'?limit=2');
        $first = $this->payload();
        self::assertCount(2, $first['items']);
        self::assertNotNull($first['nextCursor']);
        $this->client->request('GET', $this->path('invoice-audit-events').'?limit=2&cursor='.rawurlencode($first['nextCursor']));
        $second = $this->payload();
        self::assertCount(2, $second['items']);
        self::assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
    }

    private function path(string $resource): string
    {
        return '/api/organizations/'.$this->organization->getId().'/'.$resource;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
