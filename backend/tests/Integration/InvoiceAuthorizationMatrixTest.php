<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Role-permission matrix for the tenant API.
 *
 * Authorization lives in InvoiceVoter/PrincipalVoter only; the controller asks
 * the voter and never re-derives roles. Denials are 404 so a foreign or
 * unauthorized tenant is indistinguishable from a missing resource.
 */
final class InvoiceAuthorizationMatrixTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Organization $organization;

    /** @var array<string, Principal> */
    private array $principals = [];

    /** @var array<string, Membership> */
    private array $memberships = [];

    private Customer $deletableCustomer;
    private Customer $billedCustomer;
    private Invoice $draftInvoice;

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
        $this->organization = new Organization('Matrix Tenant '.$this->suffix, 'matrix-'.$this->suffix);
        $this->entityManager->persist($this->organization);
        foreach (['Owner', 'Admin', 'Member', 'Viewer'] as $role) {
            $principal = new Principal($issuer, 'matrix-'.strtolower($role).'-'.$this->suffix);
            $this->entityManager->persist($principal);
            $this->principals[$role] = $principal;
        }
        $this->entityManager->flush();
        foreach ($this->principals as $role => $principal) {
            $membership = new Membership($this->organization, $principal, $role);
            $this->entityManager->persist($membership);
            $this->memberships[$role] = $membership;
        }
        $this->deletableCustomer = new Customer($this->organization, 'Deletable '.$this->suffix);
        $this->billedCustomer = new Customer($this->organization, 'Billed '.$this->suffix);
        $this->entityManager->persist($this->deletableCustomer);
        $this->entityManager->persist($this->billedCustomer);
        $this->entityManager->flush();
        $this->draftInvoice = new Invoice(
            $this->organization,
            $this->billedCustomer,
            '2026-7'.substr($this->suffix, 0, 5),
            $this->id($this->principals['Owner']),
            new \DateTimeImmutable('today'),
            (new \DateTimeImmutable('today'))->modify('+14 days'),
            [['description' => 'Work', 'quantity' => '1.000', 'unitPrice' => '10.000', 'lineTotal' => '10.000']],
            null,
            null,
            '10.000',
            '0.000',
            '10.000',
        );
        $this->entityManager->persist($this->draftInvoice);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $orgId = $this->id($this->organization);
                $principalIds = array_map($this->id(...), array_values($this->principals));
                $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
                try {
                    $connection->executeStatement('DELETE FROM invoice_audit_events WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM invoices WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM customers WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM memberships WHERE organization_id = ?', [$orgId]);
                    $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$orgId]);
                    foreach ($principalIds as $principalId) {
                        $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$principalId]);
                    }
                } finally {
                    $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
                }
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testOwnerMayReadWriteAndDelete(): void
    {
        $this->assertRoleCan('Owner', canWrite: true, canDelete: true);
    }

    public function testAdminMayReadWriteAndDelete(): void
    {
        $this->assertRoleCan('Admin', canWrite: true, canDelete: true);
    }

    public function testMemberMayReadAndWriteButNotDelete(): void
    {
        $this->assertRoleCan('Member', canWrite: true, canDelete: false);
    }

    public function testViewerIsReadOnly(): void
    {
        $this->assertRoleCan('Viewer', canWrite: false, canDelete: false);
        $this->client->loginUser($this->principals['Viewer']);
        $path = $this->invoicePath();

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $this->client->request('PATCH', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'lineItems' => [['description' => 'Forbidden', 'quantity' => '1.000', 'unitPrice' => '999.000']],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $path.'/send', server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $path.'/mark-paid', server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('draft', (string) $this->entityManager->getConnection()->fetchOne('SELECT status FROM invoices WHERE id = ?', [$this->id($this->draftInvoice)]));
        self::assertSame('10.000', (string) $this->entityManager->getConnection()->fetchOne('SELECT subtotal FROM invoices WHERE id = ?', [$this->id($this->draftInvoice)]));
    }

    public function testSuspendedMembershipIsDenied(): void
    {
        $this->memberships['Owner']->suspend();
        $this->entityManager->flush();
        $this->client->loginUser($this->principals['Owner']);

        $this->client->request('GET', $this->customersPath());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuspendedOrganizationIsDenied(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE organizations SET status = 'suspended' WHERE id = ?",
            [$this->id($this->organization)],
        );
        $this->client->loginUser($this->principals['Owner']);

        $this->client->request('GET', $this->customersPath());
        self::assertResponseStatusCodeSame(404);
    }

    private function assertRoleCan(string $role, bool $canWrite, bool $canDelete): void
    {
        $this->client->loginUser($this->principals[$role]);

        $this->client->request('GET', $this->customersPath());
        self::assertResponseIsSuccessful(sprintf('%s must be able to read.', $role));

        $this->client->request('POST', $this->customersPath(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'name' => 'Created by '.$role.' '.$this->suffix,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($canWrite ? 201 : 404);

        $this->client->request('DELETE', $this->customersPath().'/'.$this->id($this->deletableCustomer), server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame($canDelete ? 204 : 404);

        $this->client->request('DELETE', $this->invoicePath(), server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame($canDelete ? 204 : 404);
    }

    private function customersPath(): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/customers';
    }

    private function invoicePath(): string
    {
        return '/api/organizations/'.$this->id($this->organization).'/invoices/'.$this->id($this->draftInvoice);
    }

    private function id(object $entity): string
    {
        return (new \ReflectionProperty($entity, 'id'))->getValue($entity);
    }
}
