<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Repository\PrincipalRepository;
use App\Tests\Support\InteractsWithAuthEmails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Resending a verification link is enumeration-safe: the same `202` for an
 * unknown address, a confirmed one and an unconfirmed one, and a mail only in
 * the last case. Issuing the new token retires the previous link.
 */
final class ResendVerificationTest extends WebTestCase
{
    use InteractsWithAuthEmails;

    private const ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const PASSWORD = 'Correct-Horse-9';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private string $email;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->email = 'resend-'.$this->suffix.'@example.test';
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $connection = $this->entityManager->getConnection();
                $principalIds = $connection->fetchFirstColumn(
                    'SELECT id FROM principals WHERE email LIKE ?',
                    ['%-'.$this->suffix.'@example.test'],
                );
                foreach ($principalIds as $principalId) {
                    $connection->executeStatement('DELETE FROM auth_tokens WHERE principal_id = ?', [$principalId]);
                    $connection->executeStatement('DELETE FROM memberships WHERE principal_id = ?', [$principalId]);
                }
                $connection->executeStatement('DELETE FROM organizations WHERE name LIKE ?', ['%'.$this->suffix.'%']);
                $connection->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['%-'.$this->suffix.'@example.test'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testResendingMailsAFreshLinkAndRetiresThePreviousOne(): void
    {
        $this->register();
        $firstToken = $this->lastEmailToken();

        $this->resend($this->email);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(1);
        self::assertSame($this->email, $this->lastEmailRecipient());
        $secondToken = $this->lastEmailToken();
        self::assertNotSame($firstToken, $secondToken, 'A resend must issue a new token.');

        $this->post('/api/auth/verify-email', ['token' => $secondToken, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        // The superseded link is dead even though it had not been used.
        $this->post('/api/auth/verify-email', ['token' => $firstToken, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['error']['code']);
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndSendsNothing(): void
    {
        $this->resend('never-registered-'.$this->suffix.'@example.test');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);
    }

    public function testAConfirmedAddressGetsNoMail(): void
    {
        $this->register();
        $this->post('/api/auth/verify-email', ['token' => $this->lastEmailToken(), 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->resend($this->email);

        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);

        $principal = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $principal);
        self::assertTrue($principal->isEmailVerified());
    }

    public function testTheSixthRequestInAMinuteIsThrottled(): void
    {
        $address = 'throttled-'.$this->suffix.'@example.test';

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->resend($address);
            self::assertResponseStatusCodeSame(202, sprintf('Request %d must still be accepted.', $attempt));
        }

        $this->resend($address);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_resend_attempts', $this->payload()['error']['code']);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    private function register(): void
    {
        $this->post('/api/auth/register', [
            'email' => $this->email,
            'password' => self::PASSWORD,
            'organizationName' => 'Resend '.$this->suffix,
            'displayName' => 'Resend Owner',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    private function resend(string $email): void
    {
        $this->post('/api/auth/resend-verification', ['email' => $email]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): void
    {
        $this->client->request(
            'POST',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'] + self::ORIGIN,
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    private function principals(): PrincipalRepository
    {
        $repository = self::getContainer()->get(PrincipalRepository::class);
        self::assertInstanceOf(PrincipalRepository::class, $repository);

        return $repository;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
