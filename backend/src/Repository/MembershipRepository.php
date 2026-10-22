<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findForOrganizationAndPrincipal(string $organizationId, string $principalId): ?Membership
    {
        return $this->createQueryBuilder('membership')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Membership> */
    public function findActiveForPrincipal(string $principalId): array
    {
        return $this->createQueryBuilder('membership')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :status')
            ->setParameter('principalId', $principalId)
            ->setParameter('status', 'active')
            ->orderBy('membership.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Membership> */
    public function findForOrganization(string $organizationId): array
    {
        return $this->createQueryBuilder('membership')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('membership.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
