<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Membership> */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * Serialises owner-sensitive changes for one organization.
     *
     * A transaction-scoped PostgreSQL advisory lock, keyed on the tenant id, so
     * two requests that could each remove the last Owner run one after the
     * other. The second one re-reads the roster *after* it acquires the lock and
     * therefore sees the first one's committed result.
     *
     * It takes the lock instead of locking the memberships it is about to change
     * because the dangerous case is two *different* membership rows being
     * demoted concurrently; a row lock on either one would not conflict.
     */
    public function acquireOwnerGuard(string $organizationId): void
    {
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Owner-sensitive changes must run inside a transaction.');
        }

        $connection->fetchOne(
            'SELECT pg_advisory_xact_lock(hashtext(:scope))',
            ['scope' => sprintf('organization-owners:%s', $organizationId)],
        );
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
            // The caller serializes each membership's organization; selecting it
            // here keeps the read at one query instead of one per membership.
            ->addSelect('organization')
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

    /**
     * Active members of one organization, with the person and the tenant loaded.
     *
     * The principal is fetch-joined so a member list is one query instead of one
     * per row when each membership is serialized.
     *
     * @return list<Membership>
     */
    public function findForOrganization(string $organizationId, string $principalId): array
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->addSelect('organization')
            ->innerJoin('membership.principal', 'principal')
            ->addSelect('principal')
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

    /** @return list<Membership> Owners and Admins, principals loaded */
    public function findForOrganizationAdmins(string $organizationId): array
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->innerJoin('membership.principal', 'principal')
            ->addSelect('principal')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->andWhere('membership.role IN (:adminRoles)')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->setParameter('adminRoles', ['Owner', 'Admin'])
            ->orderBy('membership.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One membership of this tenant, only for a caller who belongs to it.
     *
     * The requester join is what makes the lookup tenant-scoped: tampering with
     * the membership id from another organization yields `null`, not a row.
     */
    public function findForOrganizationById(string $organizationId, string $membershipId, string $principalId): ?Membership
    {
        return $this->createQueryBuilder('membership')
            ->innerJoin('membership.organization', 'organization')
            ->addSelect('organization')
            ->innerJoin('membership.principal', 'principal')
            ->addSelect('principal')
            ->innerJoin(
                Membership::class,
                'requesterMembership',
                'WITH',
                'requesterMembership.organization = organization AND IDENTITY(requesterMembership.principal) = :principalId AND requesterMembership.status = :requesterStatus'
            )
            ->andWhere('membership.id = :membershipId')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('membershipId', $membershipId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('requesterStatus', 'active')
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Owner count of a tenant, optionally ignoring one membership (the one being changed). */
    public function countActiveOwners(string $organizationId, ?string $excludeMembershipId = null): int
    {
        $builder = $this->createQueryBuilder('membership')
            ->select('COUNT(membership.id)')
            ->andWhere('IDENTITY(membership.organization) = :organizationId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('membership.role = :ownerRole')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('ownerRole', 'Owner');
        if (null !== $excludeMembershipId) {
            $builder->andWhere('membership.id <> :excludeId')->setParameter('excludeId', $excludeMembershipId);
        }

        return (int) $builder->getQuery()->getSingleScalarResult();
    }
}
