<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Entity\SecurityEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sprint 6 hardening: response headers, body shape, abuse budgets, audit trail.
 */
final class HardeningTest extends WebTestCase
{
    private const PASSWORD = 'Hardening-Pass-1';
    private const JSON = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
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
                    'DELETE FROM security_events WHERE type IN (?, ?) AND created_at > NOW() - INTERVAL \'5 minutes\'',
                    [SecurityEvent::LOGIN_SUCCESS, SecurityEvent::LOGIN_FAILURE],
                );
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['hardening-'.$this->suffix.'-%@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testApiResponsesCarryTheHardeningHeaders(): void
    {
        $this->client->request('GET', '/api/session', server: ['REMOTE_ADDR' => '10.7.0.1']);

        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $this->client->getResponse()->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $this->client->getResponse()->headers->get('Permissions-Policy'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertNull($this->client->getResponse()->headers->get('Strict-Transport-Security'), 'HSTS must not be sent outside production HTTPS.');
    }

    public function testAnAuthenticatedResponseIsNotCacheable(): void
    {
        $principal = $this->createPrincipal('hardening-'.$this->suffix.'-cache@example.test');
        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/me/organizations');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testANonJsonMediaTypeIsRefusedBeforeTheController(): void
    {
        $this->client->request('POST', '/api/auth/login', server: [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ORIGIN' => 'http://localhost',
            'REMOTE_ADDR' => '10.7.0.2',
        ], content: '{"email":"a@example.test","password":"x"}');

        self::assertResponseStatusCodeSame(415);
        self::assertSame('unsupported_media_type', $this->payload()['error']['code']);
    }

    public function testABodyOverOneMebibyteIsRefused(): void
    {
        $this->client->request('POST', '/api/auth/login', server: self::JSON + ['REMOTE_ADDR' => '10.7.0.3'], content: json_encode([
            'email' => 'a@example.test',
            'password' => str_repeat('x', 1_048_576),
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(413);
        self::assertSame('payload_too_large', $this->payload()['error']['code']);
    }

    public function testJsonNestedDeeperThanSixteenLevelsIsRefused(): void
    {
        $body = str_repeat('{"a":', 20).'1'.str_repeat('}', 20);

        $this->client->request('POST', '/api/auth/login', server: self::JSON + ['REMOTE_ADDR' => '10.7.0.4'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->payload()['error']['code']);
    }

    public function testTheForgotPasswordBudgetAnswersWithRateLimitedAndRetryAfter(): void
    {
        $email = 'hardening-'.$this->suffix.'-reset@example.test';

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('POST', '/api/auth/forgot-password', server: self::JSON + ['REMOTE_ADDR' => '10.7.1.'.$attempt], content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(202, sprintf('Attempt %d is inside the budget.', $attempt));
        }

        $this->client->request('POST', '/api/auth/forgot-password', server: self::JSON + ['REMOTE_ADDR' => '10.7.1.6'], content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload()['error']['code']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testTheSecurityTrailRecordsBothSignInOutcomesWithoutSecrets(): void
    {
        $principal = $this->createPrincipal('hardening-'.$this->suffix.'-trail@example.test');

        $this->login($principal->getEmail(), 'Definitely-Wrong-1');
        self::assertResponseStatusCodeSame(401);
        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseIsSuccessful();

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT type, principal_id, ip, user_agent, data::text AS data FROM security_events WHERE created_at > NOW() - INTERVAL \'1 minute\'',
        );

        $types = array_column($rows, 'type');
        self::assertContains(SecurityEvent::LOGIN_SUCCESS, $types);
        self::assertContains(SecurityEvent::LOGIN_FAILURE, $types);

        $stored = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::PASSWORD, $stored, 'A password must never reach the security trail.');
        self::assertStringNotContainsString($principal->getEmail(), $stored, 'The trail keeps a fingerprint, not the address.');
    }

    /**
     * The per-address budget must be the account's budget: capitalizing the
     * address is the cheapest way to mint a fresh bucket out of one account.
     * (A padded address is a `422` from the validator, so case is what an
     * accepted request can vary.)
     */
    public function testTheForgotPasswordBudgetSharesOneBucketAcrossAddressVariants(): void
    {
        $email = 'hardening-'.$this->suffix.'-case@example.test';
        $variants = [$email, strtoupper($email), ucfirst($email), strtolower($email), $email];

        foreach ($variants as $index => $variant) {
            $this->client->request('POST', '/api/auth/forgot-password', server: self::JSON + ['REMOTE_ADDR' => '10.8.1.'.($index + 1)], content: json_encode(['email' => $variant], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(202, sprintf('Request %d is inside the budget.', $index + 1));
        }

        $this->client->request('POST', '/api/auth/forgot-password', server: self::JSON + ['REMOTE_ADDR' => '10.8.1.9'], content: json_encode(['email' => strtoupper($email)], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload()['error']['code']);
    }

    private function createPrincipal(string $email): Principal
    {
        $principal = new Principal($email, 'Hardening User');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $principal->setPasswordHash($hasher->hashPassword($principal, self::PASSWORD));
        $principal->markEmailVerified();
        $this->entityManager->persist($principal);
        $this->entityManager->flush();

        return $principal;
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/auth/login', server: self::JSON + ['REMOTE_ADDR' => '10.7.2.1'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
