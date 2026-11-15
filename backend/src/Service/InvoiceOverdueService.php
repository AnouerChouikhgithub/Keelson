<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\InvoiceAuditEvent;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Explicit sent -> overdue transition.
 *
 * Overdue is normally derived for readers (see Invoice::effectiveStatus()), so
 * a GET never writes. This service exists for the separate, explicit operation
 * that records the transition and its audit event in one transaction. Rows are
 * locked FOR UPDATE in id order before the transition, so a concurrent payment
 * either commits first (and is skipped) or waits and observes the new status;
 * neither overwrites the other.
 */
final class InvoiceOverdueService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionBoundary $transactions,
    ) {
    }

    /** @return int number of invoices transitioned by this call */
    public function markOverdue(?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');

        return $this->transactions->run(function () use ($today): int {
            $query = $this->entityManager->createQueryBuilder()
                ->select('invoice')
                ->from(Invoice::class, 'invoice')
                ->andWhere('invoice.status = :sent')
                ->andWhere('invoice.dueDate < :today')
                ->setParameter('sent', 'sent')
                ->setParameter('today', $today)
                ->orderBy('invoice.id', 'ASC')
                ->getQuery();
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

            $transitioned = 0;
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
                ++$transitioned;
            }
            if ($transitioned > 0) {
                $this->entityManager->flush();
            }

            return $transitioned;
        });
    }
}
