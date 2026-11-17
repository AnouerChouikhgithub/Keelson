<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DataFixtures\SyntheticFixtureCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the rows this suite's fixture loader created.
 *
 * Cleanup is scoped to the fixture organizations and the catalog's own
 * addresses: other suites create principals on the same reserved domain, and a
 * cleanup that matched the whole domain would delete their rows (and fail on
 * their foreign keys).
 *
 * `invoice_audit_events` is append-only in PostgreSQL, so its trigger has to be
 * suspended for the delete and is restored in a `finally` — a half-cleaned
 * database would break every later run.
 */
trait RemovesSyntheticFixtures
{
    protected function removeSyntheticFixtureRows(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('ALTER TABLE invoice_audit_events DISABLE TRIGGER invoice_audit_events_append_only');
        try {
            $connection->executeStatement("DELETE FROM invoice_audit_events WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
            $connection->executeStatement("DELETE FROM invoices WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
            $connection->executeStatement("DELETE FROM customers WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
            $connection->executeStatement("DELETE FROM memberships WHERE organization_id IN (SELECT id FROM organizations WHERE subdomain LIKE 'fixture-%')");
            $connection->executeStatement("DELETE FROM organizations WHERE subdomain LIKE 'fixture-%'");
            $connection->executeStatement('DELETE FROM principals WHERE email IN ('.$this->quotedFixtureEmails().')');
        } finally {
            $connection->executeStatement('ALTER TABLE invoice_audit_events ENABLE TRIGGER invoice_audit_events_append_only');
        }
    }

    protected function quotedFixtureEmails(): string
    {
        return implode(',', array_map(
            static fn (array $definition): string => "'".$definition['email']."'",
            (new SyntheticFixtureCatalog())->definitions()['principals'],
        ));
    }
}
