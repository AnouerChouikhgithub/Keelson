<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceAuditEvent;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class InvoiceAuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceAuditEvent::class);
    }

    /** @return list<InvoiceAuditEvent> */
    public function findForOrganization(string $organizationId, string $principalId, ?string $invoiceId = null, int $limit = 50): array
    {
        $builder = $this->createQueryBuilder('event')
            ->innerJoin('event.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('IDENTITY(event.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('event.createdAt', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)));
        if (null !== $invoiceId) {
            $builder->andWhere('event.invoiceId = :invoiceId')->setParameter('invoiceId', $invoiceId);
        }

        return $builder->getQuery()->getResult();
    }
}
