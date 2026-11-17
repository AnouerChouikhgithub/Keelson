<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Tests\Support\IssuesCsrfTokens;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A person may edit their own name and password — and a password change keeps the
 * session that made it, while every other session is signed out by the hash
 * comparison Symfony performs on the next request.
 */
final class ProfileApiTest extends WebTestCase
{
    use IssuesCsrfTokens;

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private Principal $principal;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->principal = new Principal('profile-'.$this->suffix.'@example.test', 'Before');
        $this->principal->setPasswordHash(self::getContainer()->get(UserPasswordHasherInterface::class)
            ->hashPassword($this->principal, 'Keelson-Old-Password-1!'));
        // Sign-in requires a verified address; the account is verified so the
        // password comparison is what the test observes.
        $this->principal->markEmailVerified();
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $this->entityManager->getConnection()->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testDisplayNameCanBeChanged(): void
    {
        $this->client->loginUser($this->principal);
        $this->send('PATCH', '/api/me', ['displayName' => 'After']);

        self::assertResponseIsSuccessful();
        self::assertSame('After', $this->payload()['user']['displayName']);

        $this->send('PATCH', '/api/me', ['displayName' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('displayName', $this->payload()['error']['fields']);
    }

    public function testAWrongCurrentPasswordIsAFieldError(): void
    {
        $this->client->loginUser($this->principal);
        $this->send('POST', '/api/me/password', ['currentPassword' => 'wrong-password', 'newPassword' => 'Keelson-New-Password-2!']);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('currentPassword', $payload['error']['fields']);
    }

    public function testChangingThePasswordKeepsThisSessionAndRetiresTheOldPassword(): void
    {
        $this->client->loginUser($this->principal);
        $this->send('POST', '/api/me/password', [
            'currentPassword' => 'Keelson-Old-Password-1!',
            'newPassword' => 'Keelson-New-Password-2!',
        ]);
        self::assertResponseStatusCodeSame(204);

        // This session survives: it was rewritten with the new hash.
        $this->client->request('GET', '/api/session');
        self::assertTrue($this->payload()['authenticated']);
        $this->send('PATCH', '/api/me', ['displayName' => 'Still Signed In']);
        self::assertResponseIsSuccessful();

        // The old password no longer signs in; the new one does.
        self::ensureKernelShutdown();
        $fresh = static::createClient();
        $fresh->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'email' => $this->principal->getEmail(),
            'password' => 'Keelson-Old-Password-1!',
        ]));
        $oldAttempt = json_decode((string) $fresh->getResponse()->getContent(), true);
        self::assertSame('invalid_credentials', $oldAttempt['error']['code'] ?? null, 'the old password must not sign in any more');

        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->clear();
        $reloaded = $manager->getRepository(Principal::class)->findOneBy(['email' => $this->principal->getEmail()]);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reloaded, 'Keelson-New-Password-2!'), 'the new hash must be stored');

        $fresh->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode([
            'email' => $this->principal->getEmail(),
            'password' => 'Keelson-New-Password-2!',
        ]));
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $fresh->getResponse()->getContent(), true)['authenticated']);
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($this->client),
        ], content: json_encode($body));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
