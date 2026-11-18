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
 * The second browser lives in a second cookie jar on the same client, so the
 * two sessions are the two rows the application really wrote, without a second
 * kernel in the same test.
 */
final class SessionRevocationTest extends WebTestCase
{
    use InteractsWithAuthEmails;
    use IssuesCsrfTokens;

    private const PASSWORD = 'Correct-Horse-9';
    private const NEW_PASSWORD = 'Another-Horse-42';

    /** What `GET /api/session` answers once the session has been ended. */
    private const ANONYMOUS = ['authenticated' => false, 'user' => null, 'csrfToken' => null];

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

        $first = $this->signIn($principal->getEmail());
        $second = $this->signIn($principal->getEmail());

        self::assertNotSame(
            $this->cookieValues($first),
            $this->cookieValues($second),
            'Each sign-in must open a session of its own, otherwise this test proves nothing.',
        );
        self::assertTrue($this->sessionWith($first)['authenticated']);
        self::assertTrue($this->sessionWith($second)['authenticated']);

        $this->useCookies($first);
        $this->post('/api/auth/forgot-password', ['email' => $principal->getEmail()]);
        self::assertResponseStatusCodeSame(202);

        $this->post('/api/auth/reset-password', ['token' => $this->lastEmailToken(), 'password' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(
            self::ANONYMOUS,
            $this->sessionWith($first),
            'The session that submitted the reset must not survive it.',
        );
        self::assertSame(
            self::ANONYMOUS,
            $this->sessionWith($second),
            'A reset exists to recover a lost password: every session opened with the old one must end.',
        );
    }

    public function testAChangeKeepsTheSessionThatMadeItAndEndsTheOther(): void
    {
        $principal = $this->createPrincipal();

        $first = $this->signIn($principal->getEmail());
        $second = $this->signIn($principal->getEmail());
        self::assertTrue($this->sessionWith($second)['authenticated'], 'The second browser must be signed in before the change.');
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

        self::assertSame(
            self::ANONYMOUS,
            $this->sessionWith($second),
            'The other browser was opened with the retired password and must be signed out.',
        );
    }

    /**
     * Signs in through the real endpoint and keeps that browser's cookies.
     *
     * The jar is emptied first, so each call opens a session of its own instead of
     * reusing the cookie of the previous one.
     *
     * @return array<string, Cookie>
     */
    private function signIn(string $email): array
    {
        $this->client->getCookieJar()->clear();
        $this->post('/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(200);

        return $this->cookies();
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

    /** @return array<string, Cookie> */
    private function cookies(): array
    {
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            if ($cookie instanceof Cookie) {
                $cookies[$cookie->getName()] = $cookie;
            }
        }

        return $cookies;
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
