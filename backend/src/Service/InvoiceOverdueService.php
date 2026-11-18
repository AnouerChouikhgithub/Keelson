<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use App\Entity\Organization;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit sent -> overdue transition, one organization at a time.
 *
 * Overdue is normally derived for readers (see Invoice::effectiveStatus()), so a
 * GET never writes. This service is the separate, explicit operation that records
 * the transition, its audit event and the notifications that go with it.
 *
 * It processes each organization in its own short transactions of at most
 * {@see self::BATCH_SIZE} invoices. That matters for three reasons:
 *
 *  - a nightly run over every tenant must not hold one connection's transaction
 *    open for minutes, blocking payments;
 *  - a failure in one organization does not discard the work already committed for
 *    the others;
 *  - the work is idempotent: each batch re-selects `sent` invoices past their due
 *    date, so an invoice transitioned by an earlier run (or by a concurrent one)
 *    simply is not selected again.
 *
 * Rows are locked FOR UPDATE in id order, so a concurrent payment either commits
 * first (and is skipped) or waits and observes the new status; neither overwrites
 * the other.
 *
 * Each organization is processed inside {@see TenantContext::run()}: row-level
 * security only exposes that tenant's invoices while the transaction carries its
 * id, so the same loop that scopes the work also satisfies the database.
 */
final class InvoiceOverdueService
{
    public const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenants,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return int number of invoices transitioned by this call */
    public function markOverdue(?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');

        $transitioned = 0;
        foreach ($this->organizationIds() as $organizationId) {
            // Re-select until a batch comes back empty: the previous batch changed
            // the status of exactly the rows it handled, so the set shrinks.
            do {
                $handled = $this->tenants->run($organizationId, function () use ($organizationId, $today): int {
                    $query = $this->entityManager->createQueryBuilder()
                        ->select('invoice')
                        ->from(Invoice::class, 'invoice')
                        ->andWhere('IDENTITY(invoice.organization) = :organizationId')
                        ->andWhere('invoice.status = :sent')
                        ->andWhere('invoice.dueDate < :today')
                        ->setParameter('organizationId', $organizationId)
                        ->setParameter('sent', 'sent')
                        ->setParameter('today', $today)
                        ->orderBy('invoice.id', 'ASC')
                        ->setMaxResults(self::BATCH_SIZE)
                        ->getQuery();
                    $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

                    $count = 0;
                    foreach ($query->getResult() as $invoice) {
                        if (!$invoice->markOverdue($today)) {
                            continue;
                        }
                        $this->entityManager->persist(new InvoiceAuditEvent(
                            $invoice->getOrganization(),
                            $invoice->getId(),
                            null,
                            'invoice.overdue',
                            // The transition is automatic, not a user action: the creator is
                            // recorded as the responsible principal and the trigger is marked
                            // in metadata rather than inventing a system actor account.
                            $invoice->getCreatedByPrincipalId(),
                            ['trigger' => 'automatic'],
                        ));
                        $this->notifications->invoiceOverdue($invoice);
                        ++$count;
                    }
                    if ($count > 0) {
                        $this->entityManager->flush();
                    }

                    return $count;
                });
                $transitioned += $handled;
            } while ($handled > 0);
        }

        return $transitioned;
    }

    /**
     * Every active organization, not every organization with an overdue invoice.
     *
     * The cheaper query would be a DISTINCT over `invoices`, but an unscoped read
     * of that table returns nothing under row-level security: the tenant has to be
     * known before its rows can be selected. Organizations are not tenant-scoped,
     * so they are the correct starting point; each one is then checked for work and
     * most answer with zero rows and one short transaction.
     *
     * @return list<string>
     */
    private function organizationIds(): array
    {
        $ids = $this->entityManager->createQueryBuilder()
            ->select('organization.id')
            ->from(Organization::class, 'organization')
            ->andWhere('organization.status = :active')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
