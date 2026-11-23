<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforces tenant isolation in PostgreSQL, not only in the application.
 *
 * Every tenant row carries `organization_id`; the policies below accept a row only
 * when that column equals the transaction-local `app.organization_id` that
 * App\Tenancy\TenantContext sets. `FORCE ROW LEVEL SECURITY` is the part that
 * matters: without it the table owner (the role that runs migrations) is exempt and
 * the guarantee would silently disappear in any environment whose application role
 * happens to own the schema.
 *
 * `current_setting(..., true)` returns NULL when the variable was never set, and
 * `NULL = uuid` is NULL — not true — so an unscoped query sees no rows. The
 * policies fail closed on purpose: a forgotten TenantContext is a blank page, never
 * another tenant's data.
 *
 * Deliberately not covered:
 *  - principals, memberships, auth_tokens and sessions are identity, not tenant
 *    data. A session must be readable before any tenant is known, and a membership
 *    is what establishes tenancy in the first place; policies on them would make
 *    sign-in impossible. Authorization for those tables stays application-layer.
 *  - notifications are read per recipient *across* the organizations the person
 *    belongs to, so that policy also accepts the caller's own rows through
 *    `app.principal_id`. Its WITH CHECK stays tenant-only: a notification is always
 *    written inside the tenant it belongs to.
 *
 * The application role must be a non-owner without BYPASSRLS; /readyz fails when it
 * is not.
 */
final class Version20261121120000 extends AbstractMigration
{
    /** Tenant tables that always exist in this schema. */
    private const TABLES = ['customers', 'invoices', 'invitations'];

    private const TENANT_PREDICATE = "organization_id = current_setting('app.organization_id', true)::uuid";

    public function getDescription(): string
    {
        return 'Enable and force row-level security on customers, invoices, invoice_lines and invitations, with a tenant policy.';
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

        // Invoice lines are rows inside `invoices.line_items` (a JSON column) in this
        // schema, so there is no table to protect yet. The block applies the same
        // policy if a normalised table is ever introduced, instead of leaving a
        // future table unprotected because a migration ran before it existed.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF to_regclass('public.invoice_lines') IS NOT NULL THEN
                    ALTER TABLE invoice_lines ENABLE ROW LEVEL SECURITY;
                    ALTER TABLE invoice_lines FORCE ROW LEVEL SECURITY;
                    CREATE POLICY tenant_isolation ON invoice_lines
                        USING (organization_id = current_setting('app.organization_id', true)::uuid)
                        WITH CHECK (organization_id = current_setting('app.organization_id', true)::uuid);
                END IF;
            END
            $$;
            SQL);

        $this->addSql('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE notifications FORCE ROW LEVEL SECURITY');
        $this->addSql(<<<'SQL'
            CREATE POLICY tenant_isolation ON notifications
                USING (
                    organization_id = current_setting('app.organization_id', true)::uuid
                    OR principal_id = current_setting('app.principal_id', true)::uuid
                )
                WITH CHECK (organization_id = current_setting('app.organization_id', true)::uuid)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY tenant_isolation ON notifications');
        $this->addSql('ALTER TABLE notifications NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE notifications DISABLE ROW LEVEL SECURITY');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF to_regclass('public.invoice_lines') IS NOT NULL THEN
                    DROP POLICY IF EXISTS tenant_isolation ON invoice_lines;
                    ALTER TABLE invoice_lines NO FORCE ROW LEVEL SECURITY;
                    ALTER TABLE invoice_lines DISABLE ROW LEVEL SECURITY;
                END IF;
            END
            $$;
            SQL);

        foreach (array_reverse(self::TABLES) as $table) {
            $this->addSql(sprintf('DROP POLICY tenant_isolation ON %s', $table));
            $this->addSql(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $table));
            $this->addSql(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $table));
        }
    }
}
