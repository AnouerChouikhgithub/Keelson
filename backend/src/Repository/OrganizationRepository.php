<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findBySubdomain(string $normalizedSubdomain): ?Organization
    {
        return $this->findOneBy(['subdomain' => $normalizedSubdomain]);
    }

    public function findActiveForPrincipalInOrganization(string $organizationId, string $principalId): ?Organization
    {
        return $this->createQueryBuilder('organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('organization.id = :organizationId')
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

    /** @return list<Organization> */
    public function findForPrincipal(string $principalId): array
    {
        return $this->createQueryBuilder('organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :status')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('principalId', $principalId)
            ->setParameter('status', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('organization.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
