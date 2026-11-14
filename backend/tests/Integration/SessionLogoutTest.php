<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionLogoutTest extends WebTestCase
{
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

        $issuer = $_SERVER['OIDC_ISSUER'] ?? $_ENV['OIDC_ISSUER'] ?? null;
        self::assertNotNull($issuer, 'OIDC_ISSUER must be configured for the test session user.');
        $this->principal = new Principal($issuer, 'logout-test-'.bin2hex(random_bytes(8)));
        $this->principal->updateProfile('Logout Test User', 'logout@example.test', true);
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
                // loginUser() serializes the Principal into the test
                // session; after the request cycle the original ORM object
                // may be detached. Delete only this test's unique row by its
                // generated UUID instead of asking the UnitOfWork to remove it.
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

    public function testLogoutWithSessionProbeTokenInvalidatesTheAuthenticatedSession(): void
    {
        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();

        $session = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'authenticated' => true,
            'user' => [
                'id' => $this->principal->getId(),
                'email' => 'logout@example.test',
                'displayName' => 'Logout Test User',
            ],
        ], array_intersect_key($session, array_flip(['authenticated', 'user'])));
        self::assertIsArray($session['logout'] ?? null);
        self::assertSame('/logout', $session['logout']['url'] ?? null);
        self::assertNotSame('', $session['logout']['csrfToken'] ?? '');
        $csrfToken = $session['logout']['csrfToken'];

        // A forged token must not end the authenticated session.
        $this->client->request('POST', '/logout', ['_csrf_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        $session = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($session['authenticated'] ?? false, 'An invalid CSRF token ended the session.');
        $csrfToken = $session['logout']['csrfToken'];

        // BrowserKit retains the session cookie between these requests. The
        // token comes from the endpoint under test, not a generated test value.
        $this->client->request('POST', '/logout', ['_csrf_token' => $csrfToken]);
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/api/session');
        self::assertResponseIsSuccessful();
        self::assertSame([
            'authenticated' => false,
            'user' => null,
            'logout' => null,
        ], json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));

        // Also prove the same client no longer reaches a protected route.
        $this->client->request('GET', '/api/organizations/11111111-1111-4111-8111-111111111111/customers');
        self::assertResponseRedirects('/login');
    }
}
