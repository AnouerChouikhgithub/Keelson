<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthToken;
use App\Entity\Principal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthToken> */
final class AuthTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthToken::class);
    }

    /**
     * Finds a token that is still usable for `$type`.
     *
     * Used and expired tokens are filtered out here rather than in the caller:
     * the caller must not be able to tell those two cases apart from an unknown
     * hash, and a single "no usable row" result does exactly that.
     */
    public function findUsable(string $tokenHash, string $type, \DateTimeImmutable $now): ?AuthToken
    {
        return $this->createQueryBuilder('token')
            ->andWhere('token.tokenHash = :tokenHash')
            ->andWhere('token.type = :type')
            ->andWhere('token.usedAt IS NULL')
            ->andWhere('token.expiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('type', $type)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
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
