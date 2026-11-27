<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261201130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A database that already applied a change must survive a later migration file that
 * describes the same change.
 *
 * Version20261201130000 was Version20261201120200 before it was renumbered, so a
 * long-lived database already holds both `tenant_isolation` policies when the new
 * number runs. The migration used to issue a plain `CREATE POLICY`, which PostgreSQL
 * refuses with `42710 duplicate_object`, and that aborted the whole run: every later
 * migration was skipped, `principals.session_version` among them, until sign-in
 * answered 500.
 *
 * The suite's schema is built by `doctrine:migrations:migrate` itself, so replaying
 * the migration here is exactly that long-lived database — not a fresh one. The test
 * asserts the outcome rather than the SQL text: the replay must add no second policy
 * and must leave the tenant guarantee in place.
 */
final class RenumberedPolicyMigrationTest extends KernelTestCase
{
    private const TABLES = ['organization_ai_settings', 'ai_requests'];

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
    }

    public function testReplayingItOnAMigratedDatabaseKeepsOnePolicyPerTable(): void
    {
        // The migration runner already created these policies, which is the state the
        // renumbering broke.
        self::assertSame([1, 1], $this->policiesPerTable(), 'the migrated schema already carries one policy per ai table');

        $this->replay();

        self::assertSame([1, 1], $this->policiesPerTable(), 'replaying the migration must not create a second policy');
    }

    public function testReplayingItReassertsTheTenantGuarantee(): void
    {
        $this->replay();

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity, p.qual, p.with_check
                  FROM pg_class c
                  JOIN pg_namespace n ON n.oid = c.relnamespace
                  JOIN pg_policies p ON p.schemaname = n.nspname AND p.tablename = c.relname
                 WHERE n.nspname = 'public' AND c.relname IN (?, ?)
                 ORDER BY c.relname
                SQL,
            self::TABLES,
        );

        self::assertCount(2, $rows, 'both ai tables are still covered');
        foreach ($rows as $row) {
            self::assertTrue((bool) $row['relrowsecurity'], $row['relname'].' keeps row-level security enabled');
            self::assertTrue((bool) $row['relforcerowsecurity'], $row['relname'].' keeps it forced, so the owner is not exempt');
            self::assertStringContainsString('app.organization_id', (string) $row['qual'], $row['relname'].' still reads only the scoped tenant');
            self::assertStringContainsString('app.organization_id', (string) $row['with_check'], $row['relname'].' still refuses a row from another tenant');
        }
    }

    /**
     * Runs the migration's own statements, the way the migrator would.
     *
     * Before the fix the first `CREATE POLICY` threw `42710 duplicate_object` here and
     * the run stopped, so nothing after this migration could execute.
     */
    private function replay(): void
    {
        $migration = new Version20261201130000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * @return list<int> policy count per table, in {@see self::TABLES} order
     */
    private function policiesPerTable(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[] = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM pg_policies
                  WHERE schemaname = current_schema() AND tablename = ? AND policyname = 'tenant_isolation'",
                [$table],
            );
        }

        return $counts;
    }
}
