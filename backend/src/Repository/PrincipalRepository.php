<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Principal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PrincipalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Principal::class);
    }

    /**
     * Looks a principal up by its normalized address.
     *
     * The caller must pass an address that already went through
     * {@see Principal::normalizeEmail()}: the stored column is lower-cased, and
     * comparing a raw address would silently miss `A@b.test`.
     */
    public function findOneByEmail(string $email): ?Principal
    {
        return $this->findOneBy(['email' => $email]);
    }
}
