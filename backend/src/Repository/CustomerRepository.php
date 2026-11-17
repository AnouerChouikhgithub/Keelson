<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Customer> */
final class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findForOrganization(string $organizationId, string $customerId, string $principalId): ?Customer
    {
        return $this->createQueryBuilder('customer')
            ->innerJoin('customer.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('customer.id = :customerId')
            ->andWhere('IDENTITY(customer.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('customerId', $customerId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * One keyset page of a tenant's customers, newest first.
     *
     * The cursor is the (created_at, id) of the last row sent, not an offset: a
     * customer created while the client pages through cannot shift the window.
     * `q` matches name or email case-insensitively.
     *
     * @param array{id: string, createdAt: \DateTimeImmutable}|null $cursor
     *
     * @return list<Customer>
     */
    public function findForOrganizationList(string $organizationId, string $principalId, ?string $q = null, ?array $cursor = null, int $limit = 25): array
    {
        $builder = $this->createQueryBuilder('customer')
            ->innerJoin('customer.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('IDENTITY(customer.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->orderBy('customer.createdAt', 'DESC')
            ->addOrderBy('customer.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)) + 1);
        if (null !== $cursor) {
            $builder->andWhere('(customer.createdAt < :cursorCreatedAt OR (customer.createdAt = :cursorCreatedAt AND customer.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursor['createdAt'])
                ->setParameter('cursorId', $cursor['id']);
        }
        if (null !== $q && '' !== trim($q)) {
            // DQL has no ILIKE; lower-casing both sides gives the same
            // case-insensitive "contains" on PostgreSQL.
            $builder->andWhere('(LOWER(customer.name) LIKE :q OR LOWER(customer.email) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower(addcslashes(trim($q), '%_\\')).'%');
        }

        return $builder->getQuery()->getResult();
    }

    /**
     * Reads a tenant customer while holding a row lock.
     *
     * PostgreSQL takes a FOR KEY SHARE lock on a referenced parent row for every
     * invoice insert. FOR UPDATE conflicts with it, so a delete that locks the
     * customer first cannot race a concurrent invoice creation: the insert waits
     * until the delete commits, or the delete waits and then sees the invoice.
     */
    public function findForOrganizationForUpdate(string $organizationId, string $customerId, string $principalId): ?Customer
    {
        $query = $this->createQueryBuilder('customer')
            ->innerJoin('customer.organization', 'organization')
            ->innerJoin(Membership::class, 'membership', 'WITH', 'membership.organization = organization')
            ->andWhere('customer.id = :customerId')
            ->andWhere('IDENTITY(customer.organization) = :organizationId')
            ->andWhere('IDENTITY(membership.principal) = :principalId')
            ->andWhere('membership.status = :membershipStatus')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('customerId', $customerId)
            ->setParameter('organizationId', $organizationId)
            ->setParameter('principalId', $principalId)
            ->setParameter('membershipStatus', 'active')
            ->setParameter('organizationStatus', 'active')
            ->getQuery();
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
    }

    public function hasInvoices(string $organizationId, string $customerId): bool
    {
        return (bool) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(invoice.id)')
            ->from(Invoice::class, 'invoice')
            ->andWhere('IDENTITY(invoice.organization) = :organizationId')
            ->andWhere('IDENTITY(invoice.customer) = :customerId')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
