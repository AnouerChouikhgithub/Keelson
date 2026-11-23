<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Tests\Support\InteractsWithAuthEmails;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A password change must end every session it was not made from.
 *
 * Both sessions are opened the way two browsers open them — through
 * `POST /api/auth/login`, each with its own session cookie — rather than through
 * `loginUser()`, which plants a token no login handler ever saw. That distinction
 * is the point of the test: the version a session is compared against is written
 * at login, so a planted session would be adopted on first use and would prove
 * nothing about the flow a person actually takes.
 *
 * The second browser lives in a second cookie jar on the same client, so the two
 * sessions are the two rows the application really wrote without a second kernel
 * in the same test.
 */
final class SessionRevocationTest extends WebTestCase
{
    use InteractsWithAuthEmails;
    use IssuesCsrfTokens;

    private const PASSWORD = 'Correct-Horse-9';
    private const NEW_PASSWORD = 'Another-Horse-42';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['revoke-'.$this->suffix.'-%@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testAResetEndsEveryOtherSessionOfTheAccount(): void
    {
        $principal = $this->createPrincipal();
        [$first, $second] = $this->twoSessions($principal->getEmail());

        $this->useCookies($first);
        $this->post('/api/auth/forgot-password', ['email' => $principal->getEmail()]);
        self::assertResponseStatusCodeSame(202);

        $this->post('/api/auth/reset-password', ['token' => $this->lastEmailToken(), 'password' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->assertSignedOut($this->sessionWith($first), 'The session that submitted the reset must not survive it.');
        $this->assertSignedOut($this->sessionWith($second), 'A reset exists to recover a lost password: every session opened with the old one must end.');
    }

    /**
     * The endpoint's promise for the browser that did not ask for the change.
     *
     * Symfony's own token-hash comparison already ends this session; the test
     * pins the behaviour rather than the mechanism, so the promise outlives a
     * rewrite of how revocation is done.
     */
    public function testAChangeKeepsTheSessionThatMadeItAndEndsTheOther(): void
    {
        $principal = $this->createPrincipal();
        [$first, $second] = $this->twoSessions($principal->getEmail());

        $this->useCookies($first);
        $this->client->request('POST', '/api/me/password', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ], content: json_encode([
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $kept = $this->sessionWith($first);
        self::assertTrue($kept['authenticated'], 'The browser that changed the password must stay signed in.');
        self::assertSame($principal->getEmail(), $kept['user']['email'] ?? null);

        $this->assertSignedOut($this->sessionWith($second), 'The other browser was opened with the retired password and must be signed out.');
    }

    /**
     * Signs in twice on one account and checks the two sessions are distinct.
     *
     * Each sign-in empties the cookie jar first, so it opens a session of its own
     * instead of reusing the previous cookie.
     *
     * @return array{0: array<string, Cookie>, 1: array<string, Cookie>}
     */
    private function twoSessions(string $email): array
    {
        $first = $this->signIn($email);
        $second = $this->signIn($email);

        self::assertNotSame(
            $this->cookieValues($first),
            $this->cookieValues($second),
            'Each sign-in must open a session of its own, otherwise this test proves nothing.',
        );
        self::assertTrue($this->sessionWith($first)['authenticated']);
        self::assertTrue($this->sessionWith($second)['authenticated']);

        return [$first, $second];
    }

    /** @return array<string, Cookie> */
    private function signIn(string $email): array
    {
        $this->client->getCookieJar()->clear();
        $this->post('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);

        /** @var array<string, Cookie> $cookies */
        $cookies = $this->client->getCookieJar()->all();

        return $cookies;
    }

    /** @param array<string, Cookie> $cookies */
    private function useCookies(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    /**
     * Answers what `GET /api/session` says for one of the two browsers.
     *
     * @param array<string, Cookie> $cookies
     *
     * @return array<string, mixed>
     */
    private function sessionWith(array $cookies): array
    {
        $this->useCookies($cookies);
        $this->client->request('GET', '/api/session');

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Asserts the payload carries no signed-in identity.
     *
     * @param array<string, mixed> $payload
     */
    private function assertSignedOut(array $payload, string $message): void
    {
        self::assertFalse($payload['authenticated'] ?? null, $message);
        self::assertNull($payload['user'] ?? null, $message);
    }

    /**
     * @param array<string, Cookie> $cookies
     *
     * @return array<string, string>
     */
    private function cookieValues(array $cookies): array
    {
        return array_map(static fn (Cookie $cookie): string => (string) $cookie->getValue(), $cookies);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function createPrincipal(): Principal
    {
        $principal = new Principal('revoke-'.$this->suffix.'-'.bin2hex(random_bytes(4)).'@example.test', 'Revocation Test User');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $principal->setPasswordHash($hasher->hashPassword($principal, self::PASSWORD));
        $principal->markEmailVerified();
        $this->entityManager->persist($principal);
        $this->entityManager->flush();

        return $principal;
    }
}
