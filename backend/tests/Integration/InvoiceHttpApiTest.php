<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvoiceHttpApiTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private string $suffix;
    private Organization $organizationA;
    private Organization $organizationB;
    private Principal $principalA;
    private Principal $principalB;
    private Principal $principalWithoutMembership;
    private Customer $customerA;
    private Customer $customerB;
    private Invoice $invoiceB;

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
        $this->principalA = new Principal($issuer, 'http-test-a-'.$this->suffix);
        $this->principalB = new Principal($issuer, 'http-test-b-'.$this->suffix);
        $this->principalWithoutMembership = new Principal($issuer, 'http-test-none-'.$this->suffix);
        $this->organizationA = new Organization('HTTP Tenant A '.$this->suffix, 'http-a-'.$this->suffix);
        $this->organizationB = new Organization('HTTP Tenant B '.$this->suffix, 'http-b-'.$this->suffix);
        foreach ([$this->principalA, $this->principalB, $this->principalWithoutMembership, $this->organizationA, $this->organizationB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organizationA, $this->principalA, 'Owner'));
        $this->entityManager->persist(new Membership($this->organizationB, $this->principalB, 'Owner'));
        $this->customerA = new Customer($this->organizationA, 'Tenant A customer '.$this->suffix);
        $this->customerB = new Customer($this->organizationB, 'Foreign customer '.$this->suffix);
        $this->entityManager->persist($this->customerA);
        $this->entityManager->persist($this->customerB);
        $this->entityManager->flush();
        $this->invoiceB = new Invoice(
            $this->organizationB,
            $this->customerB,
            (new \DateTimeImmutable())->format('Y').'-000001',
            $this->id($this->principalB),
            new \DateTimeImmutable('today'),
            (new \DateTimeImmutable('today'))->modify('+14 days'),
            [['description' => 'Foreign work', 'quantity' => '1.000', 'unitPrice' => '25.000', 'lineTotal' => '25.000']],
            null,
            null,
            '25.000',
            '0.000',
            '25.000',
        );
        $this->entityManager->persist($this->invoiceB);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                    $connection->executeStatement('DELETE FROM organizations WHERE id IN (?, ?)', [$this->id($this->organizationA), $this->id($this->organizationB)]);
                    $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?, ?)', [$this->id($this->principalA), $this->id($this->principalB), $this->id($this->principalWithoutMembership)]);
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCreateAndListCustomerAsAuthorizedMember(): void
    {
        $client = $this->client;
        $this->authenticate($client, $this->principalA);
        $client->request('POST', '/api/organizations/'.$this->id($this->organizationA).'/customers', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'name' => 'New Customer '.$this->suffix,
            'email' => 'customer@example.test',
            'billingAddress' => ['street' => '1 Rue Example', 'city' => 'Tunis', 'postalCode' => '1000', 'countryCode' => 'tn'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $created = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('TN', $created['customer']['billingAddress']['countryCode']);
        $client->request('GET', '/api/organizations/'.$this->id($this->organizationA).'/customers');
        self::assertResponseIsSuccessful();
        $listedIds = array_column(json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'], 'id');
        self::assertEqualsCanonicalizing([$this->id($this->customerA), $created['customer']['id']], $listedIds);
    }

    public function testCrossTenantReadAndInvoiceCustomerReferenceAreDenied(): void
    {
        $client = $this->client;
        $this->authenticate($client, $this->principalA);
        $client->request('GET', '/api/organizations/'.$this->id($this->organizationA).'/customers/'.$this->id($this->customerB));
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/api/organizations/'.$this->id($this->organizationA).'/invoices', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'customerId' => $this->id($this->customerB),
            'issueDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'currency' => 'TND',
            'lineItems' => [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoices WHERE organization_id = ?', [$this->id($this->organizationA)]));
    }

    public function testInvalidCustomerAndCrossTenantOrganizationSelectionAreRejected(): void
    {
        $client = $this->client;
        $this->authenticate($client, $this->principalA);
        $client->request('POST', '/api/organizations/'.$this->id($this->organizationA).'/customers', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"name":" ","email":"bad"}');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/api/organizations/'.$this->id($this->organizationB).'/customers');
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignCustomerIdsCannotBeReadUpdatedOrDeletedThroughTheOwnedTenant(): void
    {
        $this->authenticate($this->client, $this->principalA);
        $path = '/api/organizations/'.$this->id($this->organizationA).'/customers/'.$this->id($this->customerB);

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('PATCH', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"name":"Stolen"}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', $path, server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Foreign customer '.$this->suffix, (string) $this->entityManager->getConnection()->fetchOne('SELECT name FROM customers WHERE id = ?', [$this->id($this->customerB)]));
    }

    public function testForeignInvoiceIdsCannotBeReadUpdatedSentOrDeletedThroughTheOwnedTenant(): void
    {
        $this->authenticate($this->client, $this->principalA);
        $ownedPath = '/api/organizations/'.$this->id($this->organizationA).'/invoices/'.$this->id($this->invoiceB);
        $foreignPath = '/api/organizations/'.$this->id($this->organizationB).'/invoices/'.$this->id($this->invoiceB);

        // Changing the tenant path fails membership authorization; keeping the
        // owned tenant path but swapping in a foreign invoice ID fails the
        // repository's parent-tenant scope.
        $this->client->request('GET', $foreignPath);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $ownedPath);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('PATCH', $ownedPath, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"lineItems":[{"description":"Stolen","quantity":"1.000","unitPrice":"999.000"}]}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $foreignPath.'/send', server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $ownedPath.'/mark-paid', server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', $ownedPath, server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('draft', (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM invoices WHERE id = ?', [$this->id($this->invoiceB)]));
        self::assertSame('25.000', (string) $this->entityManager->getConnection()->fetchOne('SELECT subtotal FROM invoices WHERE id = ?', [$this->id($this->invoiceB)]));
    }

    public function testOrganizationQueryAndBodyParametersCannotWidenTenantScope(): void
    {
        $this->authenticate($this->client, $this->principalA);
        $path = '/api/organizations/'.$this->id($this->organizationA).'/customers?organizationId='.$this->id($this->organizationB);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $items = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items'];
        self::assertSame([$this->id($this->customerA)], array_column($items, 'id'));

        $this->client->request('POST', '/api/organizations/'.$this->id($this->organizationA).'/invoices', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'organizationId' => $this->id($this->organizationB),
            'customerId' => $this->id($this->customerB),
            'issueDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'currency' => 'TND',
            'lineItems' => [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoices WHERE organization_id = ?', [$this->id($this->organizationA)]));
    }

    public function testBodyOrganizationIdCannotMoveAnInvoiceOutsideTheRouteTenant(): void
    {
        $this->authenticate($this->client, $this->principalA);
        $this->client->request('POST', '/api/organizations/'.$this->id($this->organizationA).'/invoices', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'organizationId' => $this->id($this->organizationB),
            'customerId' => $this->id($this->customerA),
            'issueDate' => '2026-10-01',
            'dueDate' => '2026-10-31',
            'currency' => 'TND',
            'lineItems' => [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoices WHERE organization_id = ?', [$this->id($this->organizationA)]));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM invoices WHERE organization_id = ?', [$this->id($this->organizationB)]));
        self::assertSame($this->id($this->customerA), (string) $this->entityManager->getConnection()->fetchOne('SELECT customer_id FROM invoices WHERE organization_id = ?', [$this->id($this->organizationA)]));
    }

    public function testCallerWithoutMembershipAndAnonymousCallerCannotReadTenantData(): void
    {
        $this->client->loginUser($this->principalWithoutMembership);
        $this->client->request('GET', '/api/organizations/'.$this->id($this->organizationA).'/customers');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/organizations/'.$this->id($this->organizationA).'/invoices');
        self::assertResponseStatusCodeSame(404);

        // Clear this test client's session cookie to verify the configured OIDC
        // entry-point redirect without booting a second kernel in one test.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/organizations/'.$this->id($this->organizationA).'/customers');
        self::assertResponseRedirects('/login');
    }

    private function authenticate(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Principal $principal): void
    {
        $client->loginUser($principal);
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
