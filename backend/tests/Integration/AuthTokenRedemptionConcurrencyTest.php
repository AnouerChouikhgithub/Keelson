<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AuthToken;
use App\Entity\Principal;
use App\Exception\AuthTokenException;
use App\Repository\AuthTokenRepository;
use App\Service\AuthTokenService;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Redemption must be decided by the database, not by a read-then-write pair.
 *
 * The failure this guards against: two requests both SELECT the token while it
 * is still unused, both UPDATE it, and both complete the flow. The fix is one
 * conditional UPDATE ... RETURNING, so the row itself arbitrates. These tests
 * assert the mechanism (a second connection waits for the first and then updates
 * zero rows) and the transaction rule (a failed mutation does not burn the link).
 */
final class AuthTokenRedemptionConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AuthTokenService $tokens;
    private AuthTokenRepository $repository;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertStringContainsString('PostgreSQL', $this->entityManager->getConnection()->getDatabasePlatform()::class);

        $tokens = self::getContainer()->get(AuthTokenService::class);
        self::assertInstanceOf(AuthTokenService::class, $tokens);
        $this->tokens = $tokens;

        $repository = self::getContainer()->get(AuthTokenRepository::class);
        self::assertInstanceOf(AuthTokenRepository::class, $repository);
        $this->repository = $repository;

        $this->principal = new Principal('token-race-'.bin2hex(random_bytes(6)).'@example.test', 'Token Race');
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM principals WHERE id = ?',
                [$this->principal->getId()],
            );
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    /**
     * The same link, redeemed twice through the repository, wins exactly once.
     */
    public function testASecondRedemptionOfTheSameTokenReturnsNothing(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $hash = hash('sha256', $plaintext);
        $now = new \DateTimeImmutable();

        self::assertSame($this->principal->getId(), $this->repository->consume($hash, AuthToken::TYPE_VERIFY, $now));
        self::assertNull($this->repository->consume($hash, AuthToken::TYPE_VERIFY, $now));

        $used = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ? AND used_at IS NOT NULL',
            [$hash],
        );
        self::assertSame(1, (int) $used);
    }

    /**
     * Two connections race the same UPDATE; the loser cannot also succeed.
     *
     * The second connection is given a lock timeout instead of being allowed to
     * wait: PostgreSQL makes it wait on the row the first transaction is
     * updating, which is exactly the serialisation the fix relies on. After the
     * first commits, the retry matches no row because `used_at` is no longer
     * NULL — the predicate is re-evaluated, not remembered.
     */
    public function testConcurrentRedemptionSerialisesOnTheTokenRow(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $hash = hash('sha256', $plaintext);
        $now = new \DateTimeImmutable();

        $params = $this->entityManager->getConnection()->getParams();
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $sql = 'UPDATE auth_tokens SET used_at = :now
                WHERE token_hash = :hash AND type = :type AND used_at IS NULL AND expires_at > :now
                RETURNING principal_id';
        $bind = [
            'now' => $now,
            'hash' => $hash,
            'type' => AuthToken::TYPE_VERIFY,
        ];
        $types = ['now' => Types::DATETIMETZ_IMMUTABLE];

        try {
            $first->beginTransaction();
            self::assertSame($this->principal->getId(), (string) $first->fetchOne($sql, $bind, $types));

            $second->executeStatement("SET lock_timeout = '300ms'");
            $second->beginTransaction();
            try {
                $second->fetchOne($sql, $bind, $types);
                self::fail('The competing redemption should wait for the first transaction, not proceed.');
            } catch (\Throwable $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), '55P03') || str_contains($exception->getMessage(), 'lock timeout'),
                    sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
                );
            }
            $second->rollBack();

            $first->commit();

            $second->beginTransaction();
            self::assertFalse($second->fetchOne($sql, $bind, $types), 'The token must not be redeemable twice.');
            $second->commit();
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $first->close();
            $second->close();
        }
    }

    /**
     * A mutation that throws must leave the link usable: the UPDATE is rolled
     * back with it, so the user can retry the same link instead of requesting a
     * new one.
     */
    public function testAFailedMutationRollsTheConsumptionBack(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_RESET);
        $hash = hash('sha256', $plaintext);

        try {
            $this->tokens->redeem($plaintext, AuthToken::TYPE_RESET, static function (): void {
                throw new \RuntimeException('the account update failed');
            });
            self::fail('The mutation failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('the account update failed', $exception->getMessage());
        }

        $usedAt = $this->entityManager->getConnection()->fetchOne(
            'SELECT used_at FROM auth_tokens WHERE token_hash = ?',
            [$hash],
        );
        self::assertNull($usedAt, 'The token must not be consumed when the operation failed.');

        // And it still works: the same link completes on the retry.
        $principal = $this->tokens->redeem($plaintext, AuthToken::TYPE_RESET, static function (): void {
        });
        self::assertSame($this->principal->getId(), $principal->getId());
    }

    /**
     * An expired token is not selectable even though the row exists.
     */
    public function testAnExpiredTokenUpdatesNoRows(): void
    {
        $expired = $this->tokens->issue(
            $this->principal,
            AuthToken::TYPE_VERIFY,
            new \DateTimeImmutable('-25 hours'),
        );

        self::assertNull(
            $this->repository->consume(hash('sha256', $expired), AuthToken::TYPE_VERIFY, new \DateTimeImmutable()),
        );
        self::assertSame(
            0,
            (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ? AND used_at IS NOT NULL',
                [hash('sha256', $expired)],
            ),
        );

        $this->expectException(AuthTokenException::class);
        $this->tokens->redeem($expired, AuthToken::TYPE_VERIFY, static function (): void {
        });
    }

    /**
     * A valid token for an address that no longer has a row cannot be redeemed.
     *
     * The foreign key cascades, so this is defence in depth; it must be a client
     * error, never a 500 built from an unexpected null.
     */
    public function testATokenWithoutAPrincipalIsRejected(): void
    {
        $plaintext = $this->tokens->issue($this->principal, AuthToken::TYPE_VERIFY);
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM principals WHERE id = ?',
            [$this->principal->getId()],
        );

        $this->expectException(AuthTokenException::class);
        $this->tokens->redeem($plaintext, AuthToken::TYPE_VERIFY, static function (): void {
        });
    }
}
