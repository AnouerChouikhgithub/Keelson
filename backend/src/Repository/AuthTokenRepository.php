<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthToken;
use App\Entity\Principal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthToken> */
final class AuthTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthToken::class);
    }

    /**
     * Atomically consumes a token and returns the principal it belongs to.
     *
     * One `UPDATE ... WHERE used_at IS NULL AND expires_at > now() RETURNING
     * principal_id` is the whole redemption. Two requests carrying the same link
     * therefore run the same statement: PostgreSQL serialises them on the row,
     * the second one re-evaluates the predicate after the first commits and
     * updates zero rows, so it gets `null`. A read followed by a separate write
     * would let both observe "usable" and both succeed.
     *
     * Used, expired and unknown tokens all produce `null`; the caller answers
     * them identically so a client cannot tell the cases apart.
     */
    public function consume(string $tokenHash, string $type, \DateTimeImmutable $now): ?string
    {
        $principalId = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE auth_tokens SET used_at = :now
             WHERE token_hash = :tokenHash AND type = :type AND used_at IS NULL AND expires_at > :now
             RETURNING principal_id',
            [
                'now' => $now,
                'tokenHash' => $tokenHash,
                'type' => $type,
            ],
            [
                'now' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return false === $principalId || null === $principalId ? null : (string) $principalId;
    }

    /**
     * Marks every unused token of `$type` for `$principal` as used.
     *
     * Issuing a new link therefore retires the previous one, so an old email
     * cannot be replayed after the user asked for a fresh one.
     */
    public function invalidateUnused(Principal $principal, string $type, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('token')
            ->update()
            ->set('token.usedAt', ':now')
            ->andWhere('token.principal = :principal')
            ->andWhere('token.type = :type')
            ->andWhere('token.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('principal', $principal)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
    }
}
