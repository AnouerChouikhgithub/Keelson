<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findForOrganization(string $organizationId, string $invitationId): ?Invitation
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.id = :invitationId')
            ->andWhere('IDENTITY(invitation.organization) = :organizationId')
            ->setParameter('invitationId', $invitationId)
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** @return list<Invitation> */
    public function findForOrganizationList(string $organizationId): array
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('IDENTITY(invitation.organization) = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('invitation.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
