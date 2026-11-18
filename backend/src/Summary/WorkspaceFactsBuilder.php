<?php

declare(strict_types=1);

namespace App\Summary;

use App\Entity\Membership;
use App\Exception\ApiException;
use App\Repository\InvoiceRepository;
use App\Repository\MembershipRepository;
use Doctrine\DBAL\Connection;

/**
 * Builds the facts a summary is allowed to describe.
 *
 * No model is involved here. Every figure comes from PostgreSQL in one of a few
 * reads, and every read carries two tenant filters: the organization id from the
 * path, and an EXISTS join on the caller's **active membership** in that same
 * organization. A foreign identifier therefore selects nothing even if a
 * controller forgot its voter, and the builder resolves the membership first and
 * answers `404` when there is none — the same answer as a missing tenant.
 *
 * The fact set is the same for every role on purpose. Every aggregate below is
 * already visible to a Viewer in the dashboard, the invoice list and the customer
 * list, so an Owner is sent nothing a Viewer could not see. The role is carried
 * into the prompt and the cache key; it never widens the data.
 *
 * Money is integer millimes computed by the database (`ROUND(total * 1000)`), as
 * in {@see \App\Service\DashboardService}; no float touches a total. Amounts are
 * restricted to the tenant's most used currency, which the facts name explicitly,
 * so two currencies are never added together.
 */
final class WorkspaceFactsBuilder
{
    private const CURRENCY_FALLBACK = 'TND';

    public function __construct(
        private readonly Connection $connection,
        private readonly MembershipRepository $memberships,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function build(string $organizationId, string $principalId, SummaryKind $kind, string $locale): WorkspaceFacts
    {
        $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principalId);
        if (!$membership instanceof Membership) {
            throw ApiException::notFound();
        }

        $today = new \DateTimeImmutable('today');
        $currency = $this->currency($organizationId, $principalId);

        return match ($kind) {
            SummaryKind::Overview => $this->overview($organizationId, $principalId, $membership, $locale, $currency, $today),
            SummaryKind::Receivables => $this->receivables($organizationId, $principalId, $membership, $locale, $currency, $today),
            SummaryKind::Activity => $this->activity($organizationId, $principalId, $membership, $locale, $currency, $today),
        };
    }

    private function overview(
        string $organizationId,
        string $principalId,
        Membership $membership,
        string $locale,
        string $currency,
        \DateTimeImmutable $today,
    ): WorkspaceFacts {
        $aggregates = $this->invoiceAggregates($organizationId, $principalId, $currency, $today);

        return new WorkspaceFacts(
            organizationId: $organizationId,
            kind: SummaryKind::Overview,
            role: $membership->getRole(),
            locale: $locale,
            currency: $currency,
            numbers: [
                'customerCount' => $this->customerCount($organizationId, $principalId),
                'invoiceCount' => (int) $aggregates['invoice_count'],
                'draftInvoiceCount' => (int) $aggregates['draft_count'],
                'sentInvoiceCount' => (int) $aggregates['sent_open_count'],
                'overdueInvoiceCount' => (int) $aggregates['overdue_count'],
                'paidInvoiceCount' => (int) $aggregates['paid_count'],
                'outstandingMillimes' => (int) $aggregates['open_millimes'] + (int) $aggregates['overdue_millimes'],
                'overdueMillimes' => (int) $aggregates['overdue_millimes'],
                'paidThisMonthMillimes' => (int) $aggregates['paid_this_month_millimes'],
            ],
            dates: ['asOf' => $today->format('Y-m-d')],
            customerNames: $this->debtorNames($organizationId, $principalId, $currency)
                ?: $this->recentCustomerNames($organizationId),
        );
    }

    private function receivables(
        string $organizationId,
        string $principalId,
        Membership $membership,
        string $locale,
        string $currency,
        \DateTimeImmutable $today,
    ): WorkspaceFacts {
        $aggregates = $this->invoiceAggregates($organizationId, $principalId, $currency, $today);
        $dates = ['asOf' => $today->format('Y-m-d')];
        $oldestDue = $aggregates['oldest_due'] ?? null;
        if (is_string($oldestDue) && '' !== $oldestDue) {
            $dates['oldestDueDate'] = $oldestDue;
        }

        return new WorkspaceFacts(
            organizationId: $organizationId,
            kind: SummaryKind::Receivables,
            role: $membership->getRole(),
            locale: $locale,
            currency: $currency,
            numbers: [
                'openInvoiceCount' => (int) $aggregates['sent_open_count'] + (int) $aggregates['overdue_count'],
                'overdueInvoiceCount' => (int) $aggregates['overdue_count'],
                'dueSoonInvoiceCount' => (int) $aggregates['due_soon_count'],
                'outstandingMillimes' => (int) $aggregates['open_millimes'] + (int) $aggregates['overdue_millimes'],
                'overdueMillimes' => (int) $aggregates['overdue_millimes'],
                'dueSoonMillimes' => (int) $aggregates['due_soon_millimes'],
            ],
            dates: $dates,
            customerNames: $this->debtorNames($organizationId, $principalId, $currency),
        );
    }

    private function activity(
        string $organizationId,
        string $principalId,
        Membership $membership,
        string $locale,
        string $currency,
        \DateTimeImmutable $today,
    ): WorkspaceFacts {
        $windowStart = (new \DateTimeImmutable('-6 days'))->setTime(0, 0);
        $windowStartSql = $windowStart->format('Y-m-d H:i:sP');

        $row = $this->connection->fetchAssociative(
            sprintf(<<<'SQL'
                SELECT
                    COUNT(*) FILTER (WHERE i.created_at >= :windowStart) AS created_count,
                    COUNT(*) FILTER (WHERE i.sent_at >= :windowStart) AS sent_count,
                    COUNT(*) FILTER (WHERE i.paid_at >= :windowStart) AS paid_count,
                    COALESCE(SUM(ROUND(i.total * 1000)) FILTER (WHERE i.paid_at >= :windowStart), 0) AS paid_millimes
                FROM invoices i
                WHERE i.organization_id = :organizationId
                  AND i.currency = :currency
                  AND %s
                SQL, $this->membershipExists('i')),
            [
                'organizationId' => $organizationId,
                'principalId' => $principalId,
                'currency' => $currency,
                'windowStart' => $windowStartSql,
            ],
        );
        if (false === $row) {
            throw new \RuntimeException('The activity aggregate query returned no row.');
        }

        $customersCreated = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM customers c WHERE c.organization_id = :organizationId AND c.created_at >= :windowStart AND %s',
                $this->membershipExists('c'),
            ),
            [
                'organizationId' => $organizationId,
                'principalId' => $principalId,
                'windowStart' => $windowStartSql,
            ],
        );

        return new WorkspaceFacts(
            organizationId: $organizationId,
            kind: SummaryKind::Activity,
            role: $membership->getRole(),
            locale: $locale,
            currency: $currency,
            numbers: [
                'windowDays' => 7,
                'invoicesCreated' => (int) $row['created_count'],
                'invoicesSent' => (int) $row['sent_count'],
                'invoicesPaid' => (int) $row['paid_count'],
                'paidMillimes' => (int) $row['paid_millimes'],
                'customersCreated' => $customersCreated,
            ],
            dates: [
                'windowStart' => $windowStart->format('Y-m-d'),
                'asOf' => $today->format('Y-m-d'),
            ],
            customerNames: $this->recentCustomerNames($organizationId),
        );
    }

    /**
     * One scan for every invoice count and sum the summary needs.
     *
     * `FILTER` keeps it a single pass. Overdue is derived from the due date the
     * same way the list and the dashboard derive it, so the summary cannot
     * disagree with the screen behind it.
     *
     * @return array<string, mixed>
     */
    private function invoiceAggregates(string $organizationId, string $principalId, string $currency, \DateTimeImmutable $today): array
    {
        $row = $this->connection->fetchAssociative(
            sprintf(<<<'SQL'
                SELECT
                    COUNT(*) AS invoice_count,
                    COUNT(*) FILTER (WHERE i.status = 'draft') AS draft_count,
                    COUNT(*) FILTER (WHERE i.status = 'sent' AND i.due_date >= :today) AS sent_open_count,
                    COUNT(*) FILTER (WHERE i.status = 'overdue' OR (i.status = 'sent' AND i.due_date < :today)) AS overdue_count,
                    COUNT(*) FILTER (WHERE i.status = 'paid') AS paid_count,
                    COALESCE(SUM(ROUND(i.total * 1000)) FILTER (WHERE i.status = 'sent' AND i.due_date >= :today), 0) AS open_millimes,
                    COALESCE(SUM(ROUND(i.total * 1000)) FILTER (WHERE i.status = 'overdue' OR (i.status = 'sent' AND i.due_date < :today)), 0) AS overdue_millimes,
                    COALESCE(SUM(ROUND(i.total * 1000)) FILTER (WHERE i.status = 'paid' AND i.paid_at >= :monthStart), 0) AS paid_this_month_millimes,
                    COUNT(*) FILTER (WHERE i.status = 'sent' AND i.due_date >= :today AND i.due_date <= :dueSoon) AS due_soon_count,
                    COALESCE(SUM(ROUND(i.total * 1000)) FILTER (WHERE i.status = 'sent' AND i.due_date >= :today AND i.due_date <= :dueSoon), 0) AS due_soon_millimes,
                    MIN(i.due_date) FILTER (WHERE i.status = 'sent' OR i.status = 'overdue') AS oldest_due
                FROM invoices i
                WHERE i.organization_id = :organizationId
                  AND i.currency = :currency
                  AND %s
                SQL, $this->membershipExists('i')),
            [
                'organizationId' => $organizationId,
                'principalId' => $principalId,
                'currency' => $currency,
                'today' => $today->format('Y-m-d'),
                'dueSoon' => $today->modify('+7 days')->format('Y-m-d'),
                'monthStart' => (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->format('Y-m-d H:i:sP'),
            ],
        );
        if (false === $row) {
            throw new \RuntimeException('The summary aggregates query returned no row.');
        }

        return $row;
    }

    /**
     * The five largest outstanding balances, by name.
     *
     * Only the display name leaves the database: the join is there to sum by
     * customer, not to select contact details.
     *
     * @return list<string>
     */
    private function debtorNames(string $organizationId, string $principalId, string $currency): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(<<<'SQL'
                SELECT c.name AS name
                FROM invoices i
                INNER JOIN customers c ON c.id = i.customer_id AND c.organization_id = i.organization_id
                WHERE i.organization_id = :organizationId
                  AND i.currency = :currency
                  AND (i.status = 'sent' OR i.status = 'overdue')
                  AND %s
                GROUP BY c.id, c.name
                ORDER BY SUM(ROUND(i.total * 1000)) DESC, c.name ASC, c.id ASC
                LIMIT %d
                SQL, $this->membershipExists('i'), WorkspaceFacts::CUSTOMER_LIMIT),
            [
                'organizationId' => $organizationId,
                'principalId' => $principalId,
                'currency' => $currency,
            ],
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * The customers behind the newest invoices, from the repository the dashboard
     * already uses. Nothing else about those invoices is kept.
     *
     * @return list<string>
     */
    private function recentCustomerNames(string $organizationId): array
    {
        $names = [];
        foreach ($this->invoices->findRecentForOrganization($organizationId, WorkspaceFacts::CUSTOMER_LIMIT) as $invoice) {
            $name = trim($invoice->getCustomer()->getName());
            if ('' !== $name && !in_array($name, $names, true)) {
                $names[] = $name;
            }
            if (WorkspaceFacts::CUSTOMER_LIMIT === count($names)) {
                break;
            }
        }

        return $names;
    }

    private function customerCount(string $organizationId, string $principalId): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM customers c WHERE c.organization_id = :organizationId AND %s', $this->membershipExists('c')),
            ['organizationId' => $organizationId, 'principalId' => $principalId],
        );
    }

    /**
     * The currency the tenant invoices in most.
     *
     * Summing two currencies would produce a number that means nothing, so the
     * facts name one and every amount below is filtered on it.
     */
    private function currency(string $organizationId, string $principalId): string
    {
        $currency = $this->connection->fetchOne(
            sprintf(
                'SELECT i.currency FROM invoices i WHERE i.organization_id = :organizationId AND %s GROUP BY i.currency ORDER BY COUNT(*) DESC, i.currency ASC LIMIT 1',
                $this->membershipExists('i'),
            ),
            ['organizationId' => $organizationId, 'principalId' => $principalId],
        );

        return is_string($currency) && '' !== $currency ? $currency : self::CURRENCY_FALLBACK;
    }

    /**
     * The second tenant filter: the caller's active membership of this tenant.
     *
     * It repeats what the voter and the builder already checked so that the
     * queries stay correct on their own — defence in depth, not the only layer.
     */
    private function membershipExists(string $tenantAlias): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM memberships m INNER JOIN organizations o ON o.id = m.organization_id'
            .' WHERE m.organization_id = %1$s.organization_id AND m.principal_id = :principalId'
            ." AND m.status = 'active' AND o.status = 'active')",
            $tenantAlias,
        );
    }
}
