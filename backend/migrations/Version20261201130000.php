<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the row-level-security policy the AI tables should have shipped with.
 *
 * `organization_ai_settings` and `ai_requests` are tenant tables: both carry
 * `organization_id` and both are read on behalf of one organization. They were created
 * in Version20261201120000 and Version20261201120100 without a policy, which left them
 * protected by the application layer alone — the one exception the schema does not
 * allow (see docs/adr/0003-postgresql-row-level-security.md). The rule is that a tenant
 * table never exists without a policy, so this migration closes the gap.
 *
 * `ENABLE` and `FORCE` are both needed, exactly as for the other tenant tables: without
 * FORCE the table owner (the role that runs migrations) is exempt and the guarantee
 * would silently disappear in an environment whose application role owns the schema.
 *
 * The predicate is the same as everywhere else, and it fails closed: an unscoped query
 * sees no rows rather than every row. Every code path that touches these tables sets
 * `app.organization_id` — the request listener for tenant routes, and both
 * App\Ai\AiGateway and App\Controller\AiStatusController for the routes that name their
 * organization in a query parameter or a body.
 *
 * This file was renumbered from Version20261201120200. A database that applied the
 * earlier number already holds both policies, and PostgreSQL has no `CREATE POLICY IF
 * NOT EXISTS`: a plain `CREATE POLICY` aborts the whole run with `42710
 * duplicate_object` before the migrations after this one (principals.session_version,
 * for instance) can execute. The policy created here is identical to the one the earlier
 * number created, so an existing `tenant_isolation` is accepted as-is. `ENABLE` and
 * `FORCE` are re-applied unconditionally — both are idempotent — so the guarantee is
 * reasserted even where the policy was already present.
 */
final class Version20261201130000 extends AbstractMigration
{
    private const TABLES = ['organization_ai_settings', 'ai_requests'];

    private const TENANT_PREDICATE = "organization_id = current_setting('app.organization_id', true)::uuid";

    /**
     * Creates the tenant policy only when the table does not have it yet.
     *
     * `%1$s` is the table name, `%2$s` the tenant predicate (repeated in USING and
     * WITH CHECK). A database that ran the earlier migration number already has the
     * identical policy, so re-creating it there would be a duplicate, not a change.
     */
    private const ENSURE_TENANT_POLICY = <<<'SQL'
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_policies
                WHERE schemaname = current_schema()
                  AND tablename = '%1$s'
                  AND policyname = 'tenant_isolation'
            ) THEN
                CREATE POLICY tenant_isolation ON %1$s USING (%2$s) WITH CHECK (%2$s);
            END IF;
        END
        $$;
        SQL;

    public function getDescription(): string
    {
        return 'Enable and force row-level security on organization_ai_settings and ai_requests, with a tenant policy.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf(self::ENSURE_TENANT_POLICY, $table, self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->addSql(sprintf('DROP POLICY tenant_isolation ON %s', $table));
            $this->addSql(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
        }
    }
}
