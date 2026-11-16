<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * One listener guards every unsafe API request: same origin when an Origin
 * header is present, and the `api` CSRF token once a session exists.
 */
final class CsrfProtectionTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private Principal $principal;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->principal = new Principal('csrf-'.bin2hex(random_bytes(6)).'@example.test', 'CSRF Test User');
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->client->loginUser($this->principal, 'main');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen() && isset($this->principal)) {
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

    public function testAMissingTokenIsRejected(): void
    {
        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_invalid', $this->payload()['error']['code']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAWrongTokenIsRejected(): void
    {
        $this->client->request('POST', '/api/auth/logout', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'not-the-session-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_invalid', $this->payload()['error']['code']);
    }

    public function testTheIssuedTokenIsAccepted(): void
    {
        $token = $this->csrfToken($this->client);

        $this->client->request('POST', '/api/auth/logout', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAForeignOriginIsRejectedEvenOnAnExemptPath(): void
    {
        // Login needs no CSRF token (there is no session yet) but must still come
        // from the application's own origin.
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://evil.example'],
            content: '{"email":"csrf@example.test","password":"whatever"}',
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame('csrf_invalid', $this->payload()['error']['code']);
    }

    public function testReadsAreNotGuarded(): void
    {
        $this->client->request('GET', '/api/session');

        self::assertResponseStatusCodeSame(200);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
