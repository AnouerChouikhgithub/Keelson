<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes the case-insensitive `q` search.
 *
 * The list queries filter with `LOWER(column) LIKE '%term%'`, which no btree
 * index can serve: the leading wildcard makes it a scan. `pg_trgm` GIN indexes
 * do serve it, because they index the trigrams of the value rather than its
 * prefix.
 *
 * These are expression indexes, which Doctrine cannot express in entity
 * mapping, so they live only here and must not be dropped by a schema update.
 * The cursor indexes (`organization_id, created_at DESC, id DESC`) already exist
 * in Version20261116120000.
 */
final class Version20261116170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pg_trgm GIN indexes for the case-insensitive list search.';
    }

    public function up(Schema $schema): void
    {
        // PHP's requirement is only that PostgreSQL offers the extension; the
        // role running migrations must be allowed to create it (a superuser, or
        // an administrator running CREATE EXTENSION once beforehand).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX idx_customers_name_trgm ON customers USING gin (lower(name) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_customers_email_trgm ON customers USING gin (lower(email) gin_trgm_ops)');
        $this->addSql('CREATE INDEX idx_invoices_number_trgm ON invoices USING gin (lower(number) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoices_number_trgm');
        $this->addSql('DROP INDEX idx_customers_email_trgm');
        $this->addSql('DROP INDEX idx_customers_name_trgm');
        // The extension is deliberately left installed: dropping it would fail
        // while any other object still uses its operator classes.
    }
}
