<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
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
 */
final class InvoiceOverdueService
{
    public const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionBoundary $transactions,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return int number of invoices transitioned by this call */
    public function markOverdue(?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');

        $transitioned = 0;
        foreach ($this->organizationIds($today) as $organizationId) {
            // Re-select until a batch comes back empty: the previous batch changed
            // the status of exactly the rows it handled, so the set shrinks.
            do {
                $handled = $this->transactions->run(function () use ($organizationId, $today): int {
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

    /** @return list<string> */
    private function organizationIds(\DateTimeImmutable $today): array
    {
        $ids = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(invoice.organization)')
            ->from(Invoice::class, 'invoice')
            ->andWhere('invoice.status = :sent')
            ->andWhere('invoice.dueDate < :today')
            ->setParameter('sent', 'sent')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
