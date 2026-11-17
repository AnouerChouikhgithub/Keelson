<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Signing out is a state change, so it is CSRF-protected like every other
 * authenticated write, and it must leave the client holding a dead session.
 */
final class SessionLogoutTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private const UNSAFE_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Principal $principal;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $this->principal = new Principal('logout-test-'.bin2hex(random_bytes(8)).'@example.test', 'Logout Test User');
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();

        // KernelBrowser installs the authenticated Principal in the real
        // Symfony firewall/session; requests below use the same cookie jar.
        $this->client->loginUser($this->principal, 'main');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen() && isset($this->principal)) {
                // loginUser() serializes the Principal into the test session;
                // after the request cycle the original ORM object may be
                // detached. Delete only this test's unique row by its generated
                // UUID instead of asking the UnitOfWork to remove it.
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM principals WHERE id = ?',
                    [$this->principal->getId()],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testTheSessionProbeReturnsTheCallersIdentityAndCsrfToken(): void
    {
        $this->client->request('GET', '/api/session');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([
            'authenticated' => true,
            'user' => [
                'id' => $this->principal->getId(),
                'email' => $this->principal->getEmail(),
                'displayName' => 'Logout Test User',
            ],
        ], array_intersect_key($this->payload(), array_flip(['authenticated', 'user'])));
        self::assertNotSame('', $this->payload()['csrfToken'] ?? '');
    }

    public function testLogoutRefusesAForgedCsrfTokenAndAcceptsTheIssuedOne(): void
    {
        $token = $this->csrfToken($this->client);

        $this->client->request('POST', '/api/auth/logout', server: self::UNSAFE_ORIGIN + ['HTTP_X_CSRF_TOKEN' => 'forged-token']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => ['code' => 'csrf_invalid', 'message' => 'A valid CSRF token is required for this request.']], $this->payload());

        $this->client->request('GET', '/api/session');
        self::assertTrue($this->payload()['authenticated'] ?? false, 'A forged token ended the session.');

        // BrowserKit retains the session cookie between these requests, and the
        // token comes from the endpoint under test rather than a test helper.
        $this->client->request('POST', '/api/auth/logout', server: self::UNSAFE_ORIGIN + ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/session');
        self::assertSame(
            ['authenticated' => false, 'user' => null, 'csrfToken' => null],
            $this->payload(),
        );

        // The same cookie jar can no longer reach a protected route, and the
        // answer is JSON rather than a redirect to a login page.
        $this->client->request('GET', '/api/me/organizations');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthenticated', $this->payload()['error']['code'] ?? null);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
