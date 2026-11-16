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

    /**
     * One keyset page of the audit trail, newest first.
     *
     * `q` filters on the action name, which is the only text column an operator
     * can meaningfully search for ("invoice.paid", "customer.deleted").
     *
     * @param array{id: string, createdAt: \DateTimeImmutable}|null $cursor
     *
     * @return list<InvoiceAuditEvent>
     */
    public function findForOrganization(string $organizationId, string $principalId, ?string $invoiceId = null, int $limit = 25, ?array $cursor = null, ?string $q = null): array
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
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)) + 1);
        if (null !== $invoiceId) {
            $builder->andWhere('event.invoiceId = :invoiceId')->setParameter('invoiceId', $invoiceId);
        }
        if (null !== $cursor) {
            $builder->andWhere('(event.createdAt < :cursorCreatedAt OR (event.createdAt = :cursorCreatedAt AND event.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursor['createdAt'])
                ->setParameter('cursorId', $cursor['id']);
        }
        if (null !== $q && '' !== trim($q)) {
            $builder->andWhere('LOWER(event.action) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(addcslashes(trim($q), '%_\\')).'%');
        }

        return $builder->getQuery()->getResult();
    }
}
