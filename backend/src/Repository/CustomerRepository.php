<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

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

    /** @return list<Customer> */
    public function findForOrganizationList(string $organizationId, string $principalId, ?string $cursor = null, int $limit = 50): array
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
            ->orderBy('customer.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)) + 1);
        if (null !== $cursor) {
            $builder->andWhere('customer.id > :cursor')->setParameter('cursor', $cursor);
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
