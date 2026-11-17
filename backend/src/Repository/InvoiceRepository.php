<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invoice> */
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
            // The serializer reads the customer id; fetch it with the invoice
            // instead of triggering a lazy load per read.
            ->innerJoin('invoice.customer', 'customer')
            ->addSelect('customer')
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

    /**
     * @param array{id: string, createdAt: \DateTimeImmutable}|null $cursor
     *
     * @return list<Invoice>
     */
    public function findForOrganizationList(string $organizationId, string $principalId, ?string $status = null, int $limit = 25, ?array $cursor = null, ?string $q = null): array
    {
        $builder = $this->createQueryBuilder('invoice')
            ->innerJoin('invoice.organization', 'organization')
            // Same reason as the single read: every listed invoice is serialized
            // with its customer id.
            ->innerJoin('invoice.customer', 'customer')
            ->addSelect('customer')
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
        if (null !== $q && '' !== trim($q)) {
            // An invoice is found by its number or by who it is for.
            $builder->andWhere('(LOWER(invoice.number) LIKE :q OR LOWER(customer.name) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower(addcslashes(trim($q), '%_\\')).'%');
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

    /**
     * The newest invoices of a tenant, customer loaded with them.
     *
     * The dashboard lists five of these; fetch-joining the customer keeps that
     * list at one query instead of one per row.
     *
     * @return list<Invoice>
     */
    public function findRecentForOrganization(string $organizationId, int $limit = 5): array
    {
        return $this->createQueryBuilder('invoice')
            ->innerJoin('invoice.customer', 'customer')
            ->addSelect('customer')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('invoice.createdAt', 'DESC')
            ->addOrderBy('invoice.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();
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

    /**
     * Allocates the next invoice number for a tenant.
     *
     * Numbers are unique per organization through the database unique index, but
     * that index alone turns concurrent creates into a hard failure: both would
     * read the same maximum and only one insert could commit. Allocation is
     * therefore serialized per organization and year with a transaction-scoped
     * advisory lock, which PostgreSQL releases when the caller's transaction
     * ends. The lookup uses the (organization_id, number) unique index.
     */
    public function nextNumber(string $organizationId): string
    {
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Invoice numbers must be allocated inside a transaction.');
        }
        $year = (new \DateTimeImmutable())->format('Y');
        $connection->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:scope))', [
            'scope' => sprintf('invoice-number:%s:%s', $organizationId, $year),
        ]);
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
