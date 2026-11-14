<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findForOrganization(string $organizationId, string $customerId): ?Customer
    {
        return $this->createQueryBuilder('customer')
            ->innerJoin('customer.organization', 'organization')
            ->andWhere('customer.id = :customerId')
            ->andWhere('IDENTITY(customer.organization) = :organizationId')
            ->andWhere('organization.status = :organizationStatus')
            ->setParameter('customerId', $customerId)
            ->setParameter('organizationId', $organizationId)
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
