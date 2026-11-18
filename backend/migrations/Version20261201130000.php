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
 */
final class Version20261201130000 extends AbstractMigration
{
    private const TABLES = ['organization_ai_settings', 'ai_requests'];

    private const TENANT_PREDICATE = "organization_id = current_setting('app.organization_id', true)::uuid";

    public function getDescription(): string
    {
        return 'Enable and force row-level security on organization_ai_settings and ai_requests, with a tenant policy.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf(
                'CREATE POLICY tenant_isolation ON %s USING (%s) WITH CHECK (%s)',
                $table,
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
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
