<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\LockMode;
use Doctrine\Persistence\ManagerRegistry;

final class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findForOrganization(string $organizationId, string $invoiceId, string $principalId): ?Invoice
    {
        return $this->createQueryBuilder('invoice')
            ->innerJoin('invoice.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('invoice.id = :invoiceId')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('invoiceId', $invoiceId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Invoice> */
    public function findForOrganizationList(string $organizationId, string $principalId, ?string $status = null, int $limit = 50, ?array $cursor = null): array
    {
        $builder = $this->createQueryBuilder('invoice')
            ->innerJoin('invoice.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('invoice.createdAt', 'DESC')
            ->addOrderBy('invoice.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)) + 1);
        if (null !== $cursor) {
            $builder->andWhere('(invoice.createdAt < :cursorCreatedAt OR (invoice.createdAt = :cursorCreatedAt AND invoice.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursor['createdAt'])
                ->setParameter('cursorId', $cursor['id']);
        }
        if ('overdue' === $status) {
            $builder->andWhere('(invoice.status = :invoiceStatus OR (invoice.status = :sentStatus AND invoice.dueDate < :today))')
                ->setParameter('invoiceStatus', 'overdue')
                ->setParameter('sentStatus', 'sent')
                ->setParameter('today', new \DateTimeImmutable('today'));
        } elseif ('sent' === $status) {
            $builder->andWhere('invoice.status = :invoiceStatus AND invoice.dueDate >= :today')
                ->setParameter('invoiceStatus', 'sent')
                ->setParameter('today', new \DateTimeImmutable('today'));
        } elseif (null !== $status) {
            $builder->andWhere('invoice.status = :invoiceStatus')->setParameter('invoiceStatus', $status);
        }

        return $builder->getQuery()->getResult();
    }

    public function findForOrganizationForUpdate(string $organizationId, string $invoiceId, string $principalId): ?Invoice
    {
        $query = $this->createQueryBuilder('invoice')
            ->innerJoin('invoice.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('invoice.id = :invoiceId')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('invoiceId', $invoiceId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
    }

    public function nextNumber(string $organizationId): string
    {
        $year = (new \DateTimeImmutable())->format('Y');
        $latestNumber = $this->createQueryBuilder('invoice')
            ->select('invoice.number')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->andWhere('invoice.number LIKE :prefix')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('prefix', $year.'-%')
            ->orderBy('invoice.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        $next = 1;
        if (is_array($latestNumber) && preg_match('/^'.preg_quote($year, '/').'-([0-9]{6})$/', (string) $latestNumber['number'], $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return sprintf('%s-%06d', $year, $next);
    }
}
