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
 *
 * Redeeming that link also sets the password. Registration already stores a hash,
 * but the person who submitted the address is not necessarily the person who
 * owns the mailbox: without the password on the verification request, whoever
 * registered an address first could sign in with the password they chose as soon
 * as the real owner clicked the link (account pre-hijacking).
 */
final class RegistrationFlowTest extends WebTestCase
{
    use InteractsWithAuthEmails;

    private const ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const PASSWORD = 'Correct-Horse-9';

    /**
     * Chosen by the mailbox owner on the verification screen, and different from
     * the password stored when the address was registered.
     */
    private const CHOSEN_PASSWORD = 'Mailbox-Owner-42';

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

        $this->verify($token);
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

    /**
     * The hash stored at registration is inert until the mailbox is confirmed.
     *
     * This is the second half of the pre-hijacking defence: even the password
     * whoever registered the address chose cannot open the account.
     */
    public function testTheHashStoredAtRegistrationCannotSignInBeforeVerification(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(201);

        $this->login($this->email, self::PASSWORD);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('email_not_verified', $this->payload()['error']['code']);
    }

    public function testAVerificationLinkIsNotConsumedWhenThePasswordIsMissing(): void
    {
        $this->register();
        $token = $this->lastEmailToken();

        $this->post('/api/auth/verify-email', ['token' => $token]);

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload();
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('password', $payload['error']['fields'], 'The password field must be named in the answer.');

        // The link was not burned by the rejected attempt.
        $this->entityManager->clear();
        $stillUnverified = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $stillUnverified);
        self::assertFalse($stillUnverified->isEmailVerified());

        $this->verify($token);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Pre-hijacking, end to end: an attacker registers a victim's address with a
     * password they know, the victim confirms the address, and the attacker's
     * password must be gone.
     */
    public function testVerificationReplacesThePasswordStoredByWhoeverRegisteredTheAddress(): void
    {
        $this->register();
        self::assertResponseStatusCodeSame(201);
        $token = $this->lastEmailToken();

        // The password the attacker chose is inert before confirmation…
        $this->login($this->email, self::PASSWORD);
        self::assertResponseStatusCodeSame(403);

        // …and the mailbox owner replaces it while confirming.
        $this->verify($token, self::CHOSEN_PASSWORD);
        self::assertResponseStatusCodeSame(204);

        $this->login($this->email, self::PASSWORD);
        self::assertResponseStatusCodeSame(401, 'The password chosen by whoever registered the address must no longer work.');
        self::assertSame('invalid_credentials', $this->payload()['error']['code']);

        $this->login($this->email, self::CHOSEN_PASSWORD);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->payload()['authenticated']);
    }

    public function testTheVerificationLinkWorksOnlyOnce(): void
    {
        $this->register();
        $token = $this->lastEmailToken();

        $this->verify($token);
        self::assertResponseStatusCodeSame(204);

        $this->verify($token);
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

        $this->verify($resetToken);
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

        $this->verify($expired);
        self::assertResponseStatusCodeSame(400);
        $expiredBody = $this->payload();

        $this->verify('never-issued');
        self::assertResponseStatusCodeSame(400);
        self::assertSame($expiredBody, $this->payload(), 'An expired link must answer like an unknown one.');

        $this->entityManager->clear();
        $stillUnverified = $this->principals()->findOneByEmail($this->email);
        self::assertInstanceOf(Principal::class, $stillUnverified);
        self::assertFalse($stillUnverified->isEmailVerified());
    }

    private function register(): void
    {
        $this->post('/api/auth/register', [
            'email' => $this->email,
            'password' => self::PASSWORD,
            'organizationName' => $this->organizationName,
            'displayName' => 'Amina Owner',
        ]);
    }

    private function verify(string $token, string $password = self::CHOSEN_PASSWORD): void
    {
        $this->post('/api/auth/verify-email', ['token' => $token, 'password' => $password]);
    }

    private function login(string $email, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.9.0.1'] + self::ORIGIN,
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
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
