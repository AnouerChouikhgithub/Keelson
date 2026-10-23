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

    public function findByIssuerAndSubject(string $issuer, string $subject): ?Principal
    {
        return $this->findOneBy(['issuer' => $issuer, 'subject' => $subject]);
    }
}
