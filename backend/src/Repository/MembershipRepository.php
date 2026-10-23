<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    public function findForOrganizationAndPrincipal(string $organizationId, string $principalId): ?Membership
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Membership> */
    public function findActiveForPrincipal(string $principalId): array
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('membership.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Membership> */
    public function findForOrganization(string $organizationId, string $principalId): array
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->innerJoin(
                Membership::class,
                'requesterMembership',
                'WITH',
                'requesterMembership.organization = organization AND IDENTITY(requesterMembership.principal) = :principalId AND requesterMembership.status = :requesterStatus'
            )
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('organization.status = :organizationStatus')
            ->andWhere('membership.status = :membershipStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('requesterStatus', 'active')
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('membership.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
