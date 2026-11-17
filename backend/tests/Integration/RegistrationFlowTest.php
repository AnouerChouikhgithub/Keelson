<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Repository\PrincipalRepository;
use App\Tests\Support\InteractsWithAuthEmails;
use App\Util\Subdomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Registering creates a usable account: a principal, its first organization and
 * the owner membership, in one transaction, and a verification link the address
 * owner can redeem.
 */
final class RegistrationFlowTest extends WebTestCase
{
    use InteractsWithAuthEmails;

    private const ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private string $email;
    private string $organizationName;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->email = 'owner-'.$this->suffix.'@example.test';
        $this->organizationName = 'Northwind '.$this->suffix;
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

    public function testRegistrationCreatesTheAccountItsFirstOrganizationAndAVerifiableMailbox(): void
    {
        $this->register();

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['status' => 'verification_sent'], $this->payload());

        $principal = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $principal);
        self::assertNotNull($principal->getPasswordHash(), 'The password must be stored as a hash.');
        self::assertNotSame(self::PASSWORD, $principal->getPasswordHash());
        self::assertFalse($principal->isEmailVerified(), 'Registering must not verify the address by itself.');

        $membership = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT o.name, o.subdomain, m.role FROM memberships m
             JOIN organizations o ON o.id = m.organization_id
             WHERE m.principal_id = ?',
            [$principal->getId()],
        );
        self::assertIsArray($membership);
        self::assertSame($this->organizationName, $membership['name']);
        self::assertSame(Subdomain::fromName($this->organizationName), $membership['subdomain']);
        self::assertSame('Owner', $membership['role'], 'The first member of an organization must own it.');

        self::assertEmailCount(1);
        self::assertSame($this->email, $this->lastEmailRecipient());
        $token = $this->lastEmailToken();
        self::assertNotSame('', $token);

        // Nothing is stored in the clear: the row holds the digest only.
        $stored = (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT token_hash FROM auth_tokens WHERE principal_id = ?',
            [$principal->getId()],
        );
        self::assertSame(hash('sha256', $token), $stored);

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $verified = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $verified);
        self::assertTrue($verified->isEmailVerified());
    }

    public function testRegisteringAnExistingAddressReturnsTheSameResponseAndSendsNothing(): void
    {
        $this->register();
        self::assertEmailCount(1);
        $firstBody = $this->payload();
        $organizationsBefore = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM organizations WHERE name LIKE ?',
            ['%'.$this->suffix.'%'],
        );

        $this->register();

        // Same status and body: a caller cannot tell a taken address from a free
        // one, and no mail goes to the address owner.
        self::assertResponseStatusCodeSame(201);
        self::assertSame($firstBody, $this->payload());
        // The mail logger only reports the current request: the duplicate sent
        // nothing.
        self::assertEmailCount(0);
        self::assertSame($organizationsBefore, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM organizations WHERE name LIKE ?',
            ['%'.$this->suffix.'%'],
        ));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM principals WHERE email = ?',
            [$this->email],
        ));
    }

    public function testAShortPasswordIsRejectedWithFieldMessages(): void
    {
        $this->post('/api/auth/register', [
            'email' => $this->email,
            'password' => 'short',
            'organizationName' => $this->organizationName,
        ]);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('password', $payload['error']['fields']);
        self::assertNull($this->principals()->findOneByEmail($this->email));
        self::assertEmailCount(0);
    }

    public function testTheVerificationLinkWorksOnlyOnce(): void
    {
        $this->register();
        $token = $this->lastEmailToken();

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->post('/api/auth/verify-email', ['token' => $token]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['error']['code']);
    }

    public function testAResetTokenCannotBeUsedToVerifyAnAddress(): void
    {
        $this->register();
        $principal = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $principal);

        $tokens = self::getContainer()->get(\App\Service\AuthTokenService::class);
        $resetToken = $tokens->issue($principal, \App\Entity\AuthToken::TYPE_RESET);

        $this->post('/api/auth/verify-email', ['token' => $resetToken]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_token', $this->payload()['error']['code']);
    }

    public function testAnExpiredVerificationLinkIsIndistinguishableFromAnUnknownOne(): void
    {
        $this->register();
        $principal = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $principal);

        // Issued 25 hours ago: verification links live 24 hours.
        $tokens = self::getContainer()->get(\App\Service\AuthTokenService::class);
        $expired = $tokens->issue(
            $principal,
            \App\Entity\AuthToken::TYPE_VERIFY,
            new \DateTimeImmutable('-25 hours'),
        );

        $this->post('/api/auth/verify-email', ['token' => $expired]);
        self::assertResponseStatusCodeSame(400);
        $expiredBody = $this->payload();

        $this->post('/api/auth/verify-email', ['token' => 'never-issued']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($expiredBody, $this->payload(), 'An expired link must answer like an unknown one.');

        $this->entityManager->clear();
        $stillUnverified = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $stillUnverified);
        self::assertFalse($stillUnverified->isEmailVerified());
    }

    private const PASSWORD = 'Correct-Horse-9';

    private function register(): void
    {
        $this->post('/api/auth/register', [
            'email' => $this->email,
            'password' => self::PASSWORD,
            'organizationName' => $this->organizationName,
            'displayName' => 'Amina Owner',
        ]);
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
