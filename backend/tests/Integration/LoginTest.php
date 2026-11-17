<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Signing in with an email address and a password.
 *
 * The failures are deliberately shaped so the endpoint cannot be used to
 * discover accounts, and the unverified-address answer is only reachable once
 * the password has been accepted.
 */
final class LoginTest extends WebTestCase
{
    private const PASSWORD = 'Correct-Horse-9';

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
                    ['login-'.$this->suffix.'-%@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testASuccessfulLoginReturnsTheSamePayloadAsTheSessionProbe(): void
    {
        $principal = $this->createPrincipal('login-'.$this->suffix.'-ok@example.test');

        $this->login($principal->getEmail(), self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $payload = $this->payload();
        self::assertTrue($payload['authenticated']);
        self::assertSame([
            'id' => $principal->getId(),
            'email' => $principal->getEmail(),
            'displayName' => 'Login Test User',
        ], $payload['user']);
        self::assertNotSame('', $payload['csrfToken']);

        // The cookie jar now holds a real Symfony session, and the probe reports
        // the same identity. The token value itself may differ: it is bound to
        // the session id, which signing in can migrate.
        $this->client->request('GET', '/api/session');
        $probe = $this->payload();
        self::assertSame(
            array_intersect_key($payload, array_flip(['authenticated', 'user'])),
            array_intersect_key($probe, array_flip(['authenticated', 'user'])),
        );
        self::assertNotSame('', $probe['csrfToken']);
    }

    public function testTheTokenReturnedByLoginIsUsableOnTheNextRequest(): void
    {
        $principal = $this->createPrincipal('login-'.$this->suffix.'-token@example.test');

        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(200);
        $token = $this->payload()['csrfToken'];

        // The SPA writes immediately after signing in; if the token handed out
        // by login were tied to a session Symfony then replaced, every first
        // write would be rejected.
        $this->client->request('POST', '/api/auth/logout', server: [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testWrongPasswordAndUnknownAddressAreIndistinguishable(): void
    {
        $principal = $this->createPrincipal('login-'.$this->suffix.'-wrong@example.test');

        $this->login($principal->getEmail(), 'Not-The-Password-1');
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->payload();

        $this->login('missing-'.$this->suffix.'@example.test', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($wrongPassword, $this->payload());
        self::assertSame('invalid_credentials', $wrongPassword['error']['code']);
    }

    public function testAnUnverifiedAddressIsRefusedOnlyAfterThePasswordWasAccepted(): void
    {
        $principal = $this->createPrincipal('login-'.$this->suffix.'-unverified@example.test', verified: false);

        // Wrong password first: an unverified account must not be distinguishable
        // from an unknown one at that point.
        $this->login($principal->getEmail(), 'Not-The-Password-1');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->payload()['error']['code']);

        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('email_not_verified', $this->payload()['error']['code']);
    }

    public function testTheSixthAttemptIsThrottled(): void
    {
        $principal = $this->createPrincipal('login-'.$this->suffix.'-throttle@example.test');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login($principal->getEmail(), 'Not-The-Password-'.$attempt);
            self::assertResponseStatusCodeSame(401, sprintf('Attempt %d must still reach the password check.', $attempt));
        }

        $this->login($principal->getEmail(), 'Not-The-Password-6');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_login_attempts', $this->payload()['error']['code']);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));

        // Even the right password is refused while the limit stands.
        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheLoginPathIsNotAUserEnumerationOracleThroughValidationErrors(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'],
            content: '{"email":"not-an-address","password":"x"}',
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->payload()['error']['code']);
    }

    private function createPrincipal(string $email, bool $verified = true): Principal
    {
        $principal = new Principal($email, 'Login Test User');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $principal->setPasswordHash($hasher->hashPassword($principal, self::PASSWORD));
        if ($verified) {
            $principal->markEmailVerified();
        }
        $this->entityManager->persist($principal);
        $this->entityManager->flush();

        return $principal;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
