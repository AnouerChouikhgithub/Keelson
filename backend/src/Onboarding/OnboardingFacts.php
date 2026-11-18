<?php

declare(strict_types=1);

namespace App\Onboarding;

use Doctrine\DBAL\Connection;

/**
 * The counts behind the onboarding checklist.
 *
 * Every step is a fact the database can answer, never a client-supplied flag: a
 * "finished" checklist must survive a reload, a new browser and a colleague's screen.
 * One round trip returns all five counts, so the checklist costs a single query on a
 * tenant of any size.
 *
 * The repository layer is deliberately not extended for this: the five counts are a
 * read model of their own, and adding them to `CustomerRepository`/`InvoiceRepository`
 * would put a UI concern in the tenant's write repositories. When RLS is enabled the
 * request's tenant context already scopes these reads to the organization in the path.
 */
final class OnboardingFacts
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{steps: list<array{id: string, done: bool}>, completed: int, total: int}
     */
    public function checklist(string $organizationId): array
    {
        $counts = $this->counts($organizationId);

        $steps = [
            ['id' => 'invite_team', 'done' => $counts['members'] > 1],
            ['id' => 'add_customer', 'done' => $counts['customers'] > 0],
            ['id' => 'create_invoice', 'done' => $counts['invoices'] > 0],
            ['id' => 'send_invoice', 'done' => $counts['sentInvoices'] > 0],
            ['id' => 'record_payment', 'done' => $counts['paidInvoices'] > 0],
        ];

        $completed = 0;
        foreach ($steps as $step) {
            if ($step['done']) {
                ++$completed;
            }
        }

        return ['steps' => $steps, 'completed' => $completed, 'total' => count($steps)];
    }

    /**
     * @return array{members: int, customers: int, invoices: int, sentInvoices: int, paidInvoices: int}
     */
    private function counts(string $organizationId): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM memberships WHERE organization_id = :organizationId AND status = 'active') AS members,
                (SELECT COUNT(*) FROM customers WHERE organization_id = :organizationId) AS customers,
                (SELECT COUNT(*) FROM invoices WHERE organization_id = :organizationId) AS invoices,
                (SELECT COUNT(*) FROM invoices WHERE organization_id = :organizationId
                    AND status IN ('sent', 'overdue', 'paid')) AS sent_invoices,
                (SELECT COUNT(*) FROM invoices WHERE organization_id = :organizationId
                    AND status = 'paid') AS paid_invoices
            SQL, ['organizationId' => $organizationId]);

        if (false === $row) {
            throw new \RuntimeException('The onboarding counts query returned no row.');
        }

        return [
            'members' => (int) $row['members'],
            'customers' => (int) $row['customers'],
            'invoices' => (int) $row['invoices'],
            'sentInvoices' => (int) $row['sent_invoices'],
            'paidInvoices' => (int) $row['paid_invoices'],
        ];
    }
}
