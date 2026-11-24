<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Connection;

/**
 * The organization dashboard, in a fixed number of queries.
 *
 * Everything that is a sum, a count or a monthly bucket is computed by
 * PostgreSQL, not by loading invoices: the numbers must stay cheap on a tenant
 * with thousands of rows, and "no per-row queries" is a property of this class.
 * The five recent invoices are a single ordered query.
 *
 * Overdue is derived from the due date exactly like the invoice read path, so the
 * dashboard and the list cannot disagree about what is overdue. Money is
 * returned in millimes (integer), never as a float.
 *
 * This is the dashboard's only source of figures. The SPA reads it and aggregates
 * nothing itself, so a headline cannot silently describe the first page of a
 * longer invoice list. The same single scan produces the paid rate: a ratio of two
 * counts the database made, rounded to whole percent.
 */
final class DashboardService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceSerializer $serializer,
    ) {
    }

    /** @return array<string, mixed> */
    public function summarize(string $organizationId): array
    {
        $monthStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
        $revenueStart = $monthStart->modify('-11 months');
        // `issue_date` is a calendar date, so the "issued this month" window is a
        // half-open date range rather than an instant comparison.
        $issuedFrom = $monthStart->format('Y-m-d');
        $issuedUntil = $monthStart->modify('+1 month')->format('Y-m-d');

        // One scan for every count and sum: FILTER keeps the total column in a
        // single pass instead of one query per status.
        $aggregates = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE status = 'draft') AS draft_count,
                COUNT(*) FILTER (WHERE status = 'sent' AND due_date >= CURRENT_DATE) AS sent_count,
                COUNT(*) FILTER (WHERE status = 'overdue' OR (status = 'sent' AND due_date < CURRENT_DATE)) AS overdue_count,
                COUNT(*) FILTER (WHERE status = 'paid') AS paid_count,
                COALESCE(SUM(ROUND(total * 1000)) FILTER (WHERE status = 'sent' AND due_date >= CURRENT_DATE), 0) AS outstanding_current,
                COALESCE(SUM(ROUND(total * 1000)) FILTER (WHERE status = 'overdue' OR (status = 'sent' AND due_date < CURRENT_DATE)), 0) AS overdue_millimes,
                COALESCE(SUM(ROUND(total * 1000)) FILTER (WHERE status = 'paid' AND paid_at >= :monthStart), 0) AS paid_this_month,
                COUNT(*) FILTER (WHERE status = 'paid' AND paid_at >= :monthStart) AS paid_this_month_count,
                COUNT(*) FILTER (WHERE issue_date >= :issuedFrom AND issue_date < :issuedUntil) AS issued_this_month,
                COUNT(*) FILTER (WHERE status = 'paid' AND issue_date >= :issuedFrom AND issue_date < :issuedUntil) AS paid_of_issued_this_month
            FROM invoices
            WHERE organization_id = :organizationId
            SQL, [
            'organizationId' => $organizationId,
            'monthStart' => $monthStart->format('Y-m-d H:i:sP'),
            'issuedFrom' => $issuedFrom,
            'issuedUntil' => $issuedUntil,
        ]);

        if (false === $aggregates) {
            throw new \RuntimeException('The dashboard aggregates query returned no row.');
        }

        $revenueRows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(paid_at AT TIME ZONE 'UTC', 'YYYY-MM') AS month,
                   COALESCE(SUM(ROUND(total * 1000)), 0) AS millimes
            FROM invoices
            WHERE organization_id = :organizationId
              AND status = 'paid'
              AND paid_at >= :revenueStart
            GROUP BY 1
            SQL, [
            'organizationId' => $organizationId,
            'revenueStart' => $revenueStart->format('Y-m-d H:i:sP'),
        ]);

        $byMonth = [];
        foreach ($revenueRows as $row) {
            $byMonth[(string) $row['month']] = (int) $row['millimes'];
        }
        $revenueByMonth = [];
        for ($offset = 0; $offset < 12; ++$offset) {
            $month = $revenueStart->modify(sprintf('+%d months', $offset))->format('Y-m');
            $revenueByMonth[] = ['month' => $month, 'paidMillimes' => $byMonth[$month] ?? 0];
        }

        $customerCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM customers WHERE organization_id = :organizationId',
            ['organizationId' => $organizationId],
        );

        // The paid rate is a ratio of two integers the database counted, rounded to
        // whole percent: no money is involved, and the client never divides rows.
        $issuedThisMonth = (int) $aggregates['issued_this_month'];
        $paidOfIssuedThisMonth = (int) $aggregates['paid_of_issued_this_month'];

        return [
            'outstandingMillimes' => (int) $aggregates['outstanding_current'] + (int) $aggregates['overdue_millimes'],
            'overdueMillimes' => (int) $aggregates['overdue_millimes'],
            'overdueCount' => (int) $aggregates['overdue_count'],
            'paidThisMonthMillimes' => (int) $aggregates['paid_this_month'],
            'paidThisMonthCount' => (int) $aggregates['paid_this_month_count'],
            'issuedThisMonthCount' => $issuedThisMonth,
            'paidRateThisMonthPercent' => 0 === $issuedThisMonth
                ? 0
                : (int) round($paidOfIssuedThisMonth * 100 / $issuedThisMonth),
            'customerCount' => $customerCount,
            'statusCounts' => [
                'draft' => (int) $aggregates['draft_count'],
                'sent' => (int) $aggregates['sent_count'],
                'overdue' => (int) $aggregates['overdue_count'],
                'paid' => (int) $aggregates['paid_count'],
            ],
            'revenueByMonth' => $revenueByMonth,
            'recentInvoices' => array_map(
                fn (Invoice $invoice): array => $this->serializer->invoice($invoice),
                $this->invoices->findRecentForOrganization($organizationId, 5),
            ),
        ];
    }
}
