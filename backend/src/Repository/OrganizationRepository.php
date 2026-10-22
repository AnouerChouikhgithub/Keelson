<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findById(string $id): ?Organization
    {
        return $this->find($id);
    }

    public function findBySubdomain(string $normalizedSubdomain): ?Organization
    {
        return $this->findOneBy(['subdomain' => $normalizedSubdomain]);
    }
}
