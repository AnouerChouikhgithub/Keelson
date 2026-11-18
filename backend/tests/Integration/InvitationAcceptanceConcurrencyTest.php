<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Invitation;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Service\InvitationService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accepting an invitation twice, at the same time, must not create two accounts
 * or two memberships, and must never answer 500.
 *
 * The failure this guards against: two requests both read a usable invitation
 * (neither has taken a lock), both insert an account and a membership, and the
 * loser hits a unique index. Without a lock and without mapping that exception,
 * the second user sees a server error and is never told the link is spent.
 *
 * The pattern follows AuthTokenRedemptionConcurrencyTest: a second, independent
 * connection proves the row lock is what arbitrates, and a deliberate unique
 * violation proves the exception is a `409 invitation_unavailable`.
 */
final class InvitationAcceptanceConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InvitationService $invitations;
    private Organization $organization;
    private Principal $owner;
    private string $suffix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $invitations = self::getContainer()->get(InvitationService::class);
        self::assertInstanceOf(InvitationService::class, $invitations);
        $this->invitations = $invitations;

        $this->suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Invite Race '.$this->suffix, 'race-'.$this->suffix);
        $this->owner = new Principal('race-owner-'.$this->suffix.'@example.test', 'Race Owner');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->owner);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->suffix)) {
                // The unit under test can close the entity manager, so cleanup
                // runs on a connection of its own.
                $connection = $this->connection();
                $connection->executeStatement(
                    'DELETE FROM organizations WHERE subdomain = ?',
                    ['race-'.$this->suffix],
                );
                $connection->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['race-%-'.$this->suffix.'@example.test'],
                );
                $connection->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * The same link, accepted twice one after the other, wins exactly once and
     * answers the ordinary invalid-token error the second time.
     */
    public function testASecondAcceptanceOfTheSameLinkIsRefused(): void
    {
        $email = 'race-once-'.$this->suffix.'@example.test';
        $token = $this->issue($email);

        $accepted = $this->invitations->accept($token, null, 'Race-Winner-42', 'Race Winner');
        self::assertTrue($accepted['created']);

        try {
            $this->invitations->accept($token, null, 'Race-Loser-42', 'Race Loser');
            self::fail('A redeemed invitation must not be accepted a second time.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('invalid_token', $exception->getErrorCode());
        }

        $connection = $this->connection();
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM principals WHERE email = ?',
            [$email],
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM memberships m JOIN principals p ON p.id = m.principal_id
             WHERE p.email = ? AND m.organization_id = ?',
            [$email, $this->organization->getId()],
        ));
    }

    /**
     * Two connections, one invitation row: the first transaction's FOR UPDATE
     * makes the acceptance path wait on it, which is what serialises the race.
     *
     * The wait is proven with a short lock timeout instead of a hanging test.
     * After the holder rolls back, the same link is accepted normally, so the
     * lock is not a dead end.
     */
    public function testTheAcceptancePathTakesAWriteLockOnTheInvitationRow(): void
    {
        $email = 'race-lock-'.$this->suffix.'@example.test';
        $issued = $this->invitations->invite($this->organization, $this->owner, $email, 'Member');
        $invitationId = $issued['invitation']->getId();
        $connection = $this->entityManager->getConnection();
        $holder = DriverManager::getConnection($connection->getParams());

        try {
            $holder->beginTransaction();
            self::assertSame(
                $invitationId,
                (string) $holder->fetchOne('SELECT id FROM invitations WHERE id = ? FOR UPDATE', [$invitationId]),
            );

            $connection->executeStatement("SET lock_timeout = '300ms'");
            try {
                $this->invitations->accept($issued['token'], null, 'Race-Lock-42', 'Race Lock');
                self::fail('The acceptance must wait for the row lock instead of passing it.');
            } catch (\Throwable $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), '55P03') || str_contains($exception->getMessage(), 'lock timeout'),
                    sprintf('Expected a lock wait on the invitation row, got %s: %s', $exception::class, $exception->getMessage()),
                );
            }
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
            if ($connection->isConnected()) {
                $connection->executeStatement('SET lock_timeout = 0');
            }
        }

        // Released: the link still works, and the wait did not consume it. The
        // failed attempt closed the entity manager, so the retry takes a fresh
        // container — the same thing a new request would get.
        self::ensureKernelShutdown();
        self::bootKernel();
        $service = self::getContainer()->get(InvitationService::class);
        self::assertInstanceOf(InvitationService::class, $service);

        $accepted = $service->accept($issued['token'], null, 'Race-Lock-42', 'Race Lock');
        self::assertTrue($accepted['created']);
    }

    /**
     * A unique-constraint violation during acceptance is a conflict, never a 500.
     *
     * The trigger makes the membership insert lose the index race the way a
     * concurrent acceptance would, so the mapping is exercised deterministically:
     * the caller learns the invitation is unavailable, and the transaction is
     * rolled back so the link is not half-spent.
     */
    public function testAUniqueViolationDuringAcceptanceIsAConflict(): void
    {
        $email = 'race-conflict-'.$this->suffix.'@example.test';
        $token = $this->issue($email);
        $connection = $this->entityManager->getConnection();
        $function = 'test_reject_membership_'.$this->suffix;

        $connection->executeStatement(sprintf(
            "CREATE FUNCTION %s() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'duplicate key value violates unique constraint' USING ERRCODE = '23505'; END; $$ LANGUAGE plpgsql",
            $function,
        ));
        $connection->executeStatement(sprintf(
            'CREATE TRIGGER %s BEFORE INSERT ON memberships FOR EACH ROW EXECUTE FUNCTION %s()',
            $function,
            $function,
        ));

        try {
            $this->invitations->accept($token, null, 'Race-Conflict-42', 'Race Conflict');
            self::fail('The unique violation must surface as a domain conflict.');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('invitation_unavailable', $exception->getErrorCode());
        } finally {
            $connection->executeStatement(sprintf('DROP TRIGGER IF EXISTS %s ON memberships', $function));
            $connection->executeStatement(sprintf('DROP FUNCTION IF EXISTS %s()', $function));
        }

        $fresh = $this->connection();
        self::assertSame(
            Invitation::STATUS_PENDING,
            (string) $fresh->fetchOne('SELECT status FROM invitations WHERE token_hash = ?', [hash('sha256', $token)]),
            'A failed acceptance must leave the invitation pending.',
        );
        self::assertSame(0, (int) $fresh->fetchOne('SELECT COUNT(*) FROM principals WHERE email = ?', [$email]));
        $fresh->close();
    }

    private function issue(string $email): string
    {
        return $this->invitations->invite($this->organization, $this->owner, $email, 'Member')['token'];
    }

    /** A connection that survives the entity manager being closed by a failure. */
    private function connection(): Connection
    {
        return DriverManager::getConnection($this->entityManager->getConnection()->getParams());
    }
}
