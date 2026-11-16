<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuthToken;
use App\Entity\Principal;
use App\Service\AuthTokenService;
use App\Tests\Support\InteractsWithAuthEmails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The reset flow: request a link, redeem it once, and leave every older session
 * unable to authenticate.
 */
final class PasswordResetTest extends WebTestCase
{
    use InteractsWithAuthEmails;

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
                    ['reset-'.$this->suffix.'-%@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testRequestingAResetIsEnumerationSafe(): void
    {
        $principal = $this->createPrincipal();

        $this->forgot($principal->getEmail());
        self::assertResponseStatusCodeSame(202);
        $knownAddress = $this->client->getResponse()->getContent();
        self::assertEmailCount(1);
        self::assertSame($principal->getEmail(), $this->lastEmailRecipient());

        $this->forgot('missing-'.$this->suffix.'@example.test');
        self::assertResponseStatusCodeSame(202);
        self::assertSame($knownAddress, $this->client->getResponse()->getContent());
        // The mail logger only reports the current request.
        self::assertEmailCount(0, 'An unknown address must not receive mail.');
    }

    public function testTheResetChangesThePasswordAndCannotBeReplayed(): void
    {
        $principal = $this->createPrincipal();
        $this->forgot($principal->getEmail());
        $token = $this->lastEmailToken();

        $this->reset($token, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);

        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(401, 'The previous password must stop working.');
        $this->login($principal->getEmail(), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(200);

        $this->reset($token, 'Third-Horse-77');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['error']['code']);
    }

    public function testAnExpiredResetLinkIsRejected(): void
    {
        $principal = $this->createPrincipal();
        $tokens = self::getContainer()->get(AuthTokenService::class);
        self::assertInstanceOf(AuthTokenService::class, $tokens);

        // Reset links live one hour.
        $expired = $tokens->issue($principal, AuthToken::TYPE_RESET, new \DateTimeImmutable('-2 hours'));

        $this->reset($expired, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['error']['code']);

        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(200, 'A rejected reset must not change the password.');
    }

    public function testRedeemingAResetLinkMarksTheAddressVerified(): void
    {
        $principal = $this->createPrincipal(verified: false);
        $this->forgot($principal->getEmail());

        $this->reset($this->lastEmailToken(), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);

        $this->login($principal->getEmail(), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(200, 'Receiving the link proves control of the address.');
    }

    public function testResettingEndsTheSessionThatWasAlreadySignedIn(): void
    {
        $principal = $this->createPrincipal();
        $this->login($principal->getEmail(), self::PASSWORD);
        self::assertResponseStatusCodeSame(200);

        $this->forgot($principal->getEmail());
        $this->reset($this->lastEmailToken(), self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);

        // The session still carries the old password hash, so Symfony's context
        // listener signs it out on the next request without extra code.
        $this->client->request('GET', '/api/session');
        self::assertSame(
            ['authenticated' => false, 'user' => null, 'csrfToken' => null],
            $this->payload(),
        );
    }

    public function testANewRequestRetiresThePreviousLink(): void
    {
        $principal = $this->createPrincipal();
        $this->forgot($principal->getEmail());
        $first = $this->lastEmailToken();

        $this->forgot($principal->getEmail());
        $second = $this->lastEmailToken();
        self::assertNotSame($first, $second);
        self::assertEmailCount(1);

        $this->reset($first, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(400);

        $this->reset($second, self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(204);
    }

    private function createPrincipal(bool $verified = true): Principal
    {
        $principal = new Principal('reset-'.$this->suffix.'-'.bin2hex(random_bytes(4)).'@example.test', 'Reset Test User');
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

    private function forgot(string $email): void
    {
        $this->post('/api/auth/forgot-password', ['email' => $email]);
    }

    private function reset(string $token, string $password): void
    {
        $this->post('/api/auth/reset-password', ['token' => $token, 'password' => $password]);
    }

    private function login(string $email, string $password): void
    {
        $this->post('/api/auth/login', ['email' => $email, 'password' => $password]);
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
