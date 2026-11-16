<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findForOrganization(string $organizationId, string $invitationId, string $principalId): ?Invitation
    {
        return $this->createQueryBuilder('invitation')
            ->innerJoin('invitation.organization', 'organization')
            ->innerJoin(
                Membership::class,
                'requesterMembership',
                'WITH',
                'requesterMembership.organization = organization AND IDENTITY(requesterMembership.principal) = :principalId AND requesterMembership.status = :requesterStatus AND requesterMembership.role IN (:adminRoles)'
            )
            ->andWhere('invitation.id = :invitationId')
            ->andWhere('IDENTITY(invitation.organization) = :organizationId')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('invitationId', $invitationId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('requesterStatus', 'active')
            ->setParameter('adminRoles', ['Owner', 'Admin'])
            ->setParameter('organizationStatus', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** Public redemption lookup: the organization name is part of the answer. */
    public function findUsableByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->createQueryBuilder('invitation')
            ->innerJoin('invitation.organization', 'organization')
            ->addSelect('organization')
            ->andWhere('invitation.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A pending invitation for the same address and tenant is a conflict, not a second row. */
    public function findPendingForEmail(string $organizationId, string $email): ?Invitation
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('IDENTITY(invitation.organization) = :organizationId')
            ->andWhere('invitation.email = :email')
            ->andWhere('invitation.status = :pending')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('email', $email)
            ->setParameter('pending', Invitation::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Invitation> */
    public function findForOrganizationList(string $organizationId, string $principalId): array
    {
        return $this->createQueryBuilder('invitation')
            ->innerJoin('invitation.organization', 'organization')
            ->innerJoin(
                Membership::class,
                'requesterMembership',
                'WITH',
                'requesterMembership.organization = organization AND IDENTITY(requesterMembership.principal) = :principalId AND requesterMembership.status = :requesterStatus AND requesterMembership.role IN (:adminRoles)'
            )
            ->andWhere('IDENTITY(invitation.organization) = :organizationId')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('requesterStatus', 'active')
            ->setParameter('adminRoles', ['Owner', 'Admin'])
            ->setParameter('organizationStatus', 'active')
            ->orderBy('invitation.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
