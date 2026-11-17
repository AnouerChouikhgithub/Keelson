<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DataFixtures\SyntheticFixtureLoader;
use App\Entity\Invoice;
use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use App\Tests\Support\RemovesSyntheticFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The seeded demo accounts, exercised through the real HTTP API.
 *
 * The fixtures exist so a developer can sign in and reach every role without
 * creating accounts by hand. These tests hold that promise to the same standard
 * as production code: a real JSON login (never `loginUser()`), the session probe
 * the SPA calls, the authorization denials a role must hit, and the audit trail
 * the invoice screens read.
 *
 * The rows are seeded in setUp and removed in tearDown, so a suite run leaves the
 * database as it found it — other suites add constraints and count rows on the
 * audit table, and leftover fixture data would break them.
 */
final class DemoAccountsTest extends WebTestCase
{
    use IssuesCsrfTokens;
    use RemovesSyntheticFixtures;

    private const OWNER = 'owner@example.test';
    private const ADMIN = 'admin@example.test';
    private const MEMBER = 'member@example.test';
    private const VIEWER = 'viewer@example.test';
    private const UNVERIFIED = 'unverified@example.test';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $password;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $loader = self::getContainer()->get(SyntheticFixtureLoader::class);
        self::assertInstanceOf(SyntheticFixtureLoader::class, $loader);
        $this->password = $loader->demoPassword();
        // Loading twice must not duplicate anything, so seeding here is safe even
        // when another test in the same database has already run it.
        $loader->load();
    }

    public function testEveryVerifiedDemoAccountCanSignInAndReadItsSession(): void
    {
        foreach ([self::OWNER, self::ADMIN, self::MEMBER, self::VIEWER] as $email) {
            $payload = $this->login($email);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), sprintf('%s must be able to sign in.', $email));
            self::assertTrue($payload['authenticated'], $email);
            self::assertSame($email, $payload['user']['email'], $email);
            self::assertNotSame('', $payload['csrfToken'], $email);

            $session = $this->decode('GET', '/api/session');
            self::assertTrue($session['authenticated'], sprintf('%s must still hold a session.', $email));
            self::assertSame($email, $session['user']['email'], $email);
        }
    }

    public function testTheUnverifiedDemoAccountIsRefusedOnceThePasswordIsRight(): void
    {
        $payload = $this->login(self::UNVERIFIED);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('email_not_verified', $payload['error']['code']);
        self::assertFalse($this->decode('GET', '/api/session')['authenticated']);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $this->removeSyntheticFixtureRows($this->entityManager);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheViewerMayReadButCannotCreateCustomersOrSendInvoices(): void
    {
        $this->login(self::VIEWER);
        $organizationId = $this->organizationId(self::VIEWER, 'fixture-contoso');
        $customersPath = '/api/organizations/'.$organizationId.'/customers';

        $this->client->request('GET', $customersPath);
        self::assertResponseIsSuccessful();

        $draft = $this->draftInvoiceOf($organizationId);

        $this->client->request('POST', $customersPath, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ], content: json_encode(['name' => 'Viewer attempt'], JSON_THROW_ON_ERROR));
        // Denials are 404 on purpose: an unauthorized tenant must be
        // indistinguishable from a missing one, so the caller learns nothing.
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->decodeLast()['error']['code']);

        $this->client->request('POST', '/api/organizations/'.$organizationId.'/invoices/'.$draft->getId().'/send', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $this->decodeLast()['error']['code']);

        // The refusal is real, not cosmetic: the invoice is still a draft.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Invoice::class, $draft->getId());
        self::assertInstanceOf(Invoice::class, $reloaded);
        self::assertSame('draft', $reloaded->getStatus());
    }

    public function testTheOwnerSeesOnlyNorthwindWhileTheMemberSeesBothOrganizations(): void
    {
        $this->login(self::OWNER);
        $ownerOrganizations = array_column($this->decode('GET', '/api/me/organizations')['items'], 'name');
        self::assertSame(['Northwind Demo Co. (fixture)'], $ownerOrganizations);

        // Signing in again on the same client replaces the session user: one
        // kernel, one cookie jar, two accounts.
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $memberPayload = $this->login(self::MEMBER);
        self::assertSame(self::MEMBER, $memberPayload['user']['email']);
        $memberOrganizations = array_column($this->decode('GET', '/api/me/organizations')['items'], 'name');
        sort($memberOrganizations);
        self::assertSame(['Contoso Demo Co. (fixture)', 'Northwind Demo Co. (fixture)'], $memberOrganizations);
    }

    public function testSentAndPaidFixtureInvoicesCarryMatchingAuditEvents(): void
    {
        $connection = $this->entityManager->getConnection();

        $invoices = $connection->fetchAllAssociative(
            "SELECT i.id, i.status FROM invoices i
             JOIN organizations o ON o.id = i.organization_id
             WHERE o.subdomain LIKE 'fixture-%'"
        );
        self::assertCount(30, $invoices);
        self::assertSame(13, count(array_filter($invoices, static fn (array $row): bool => 'paid' === $row['status'])));
        self::assertSame(5, count(array_filter($invoices, static fn (array $row): bool => 'overdue' === $row['status'])));
        self::assertSame(8, count(array_filter($invoices, static fn (array $row): bool => 'sent' === $row['status'])));

        foreach ($invoices as $invoice) {
            $events = $connection->fetchFirstColumn(
                'SELECT action FROM invoice_audit_events WHERE invoice_id = ?',
                [$invoice['id']],
            );
            self::assertContains('invoice.created', $events, 'Every invoice must have a creation event.');
            if (in_array($invoice['status'], ['sent', 'overdue', 'paid'], true)) {
                self::assertContains('invoice.sent', $events, 'A sent invoice must have a sent event.');
            }
            if ('paid' === $invoice['status']) {
                self::assertContains('invoice.paid', $events, 'A paid invoice must have a paid event.');
            }
        }

        // The trail names a member of the invoice's own organization, never the
        // Viewer from the other tenant.
        $viewer = $this->entityManager->getRepository(Principal::class)->findOneBy(['email' => self::VIEWER]);
        self::assertInstanceOf(Principal::class, $viewer);
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM invoice_audit_events WHERE actor_principal_id = ?',
            [$viewer->getId()],
        ));
    }

    /** @return array<string, mixed> */
    private function login(string $email): array
    {
        $this->client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
        ], content: json_encode(['email' => $email, 'password' => $this->password], JSON_THROW_ON_ERROR));

        return $this->decodeLast();
    }

    /** @return array<string, mixed> */
    private function decode(string $method, string $path): array
    {
        $this->client->request($method, $path);

        return $this->decodeLast();
    }

    /** @return array<string, mixed> */
    private function decodeLast(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function organizationId(string $email, string $subdomain): string
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT o.id FROM organizations o
             JOIN memberships m ON m.organization_id = o.id
             JOIN principals p ON p.id = m.principal_id
             WHERE p.email = :email AND o.subdomain = :subdomain',
            ['email' => $email, 'subdomain' => $subdomain],
        );
        self::assertIsString($id);

        return $id;
    }

    private function draftInvoiceOf(string $organizationId): Invoice
    {
        $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy([
            'organization' => $organizationId,
            'status' => 'draft',
        ]);
        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }
}
