<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
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
    private Customer $customerB;

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
        $this->organizationA = new Organization('HTTP Tenant A '.$this->suffix, 'http-a-'.$this->suffix);
        $this->organizationB = new Organization('HTTP Tenant B '.$this->suffix, 'http-b-'.$this->suffix);
        foreach ([$this->principalA, $this->principalB, $this->organizationA, $this->organizationB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organizationA, $this->principalA, 'Owner'));
        $this->entityManager->persist(new Membership($this->organizationB, $this->principalB, 'Owner'));
        $this->customerB = new Customer($this->organizationB, 'Foreign customer '.$this->suffix);
        $this->entityManager->persist($this->customerB);
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
                    $connection->executeStatement('DELETE FROM principals WHERE id IN (?, ?)', [$this->id($this->principalA), $this->id($this->principalB)]);
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
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['items']);
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

    private function authenticate(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Principal $principal): void
    {
        $client->loginUser($principal);
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
