<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames the index that covers `invoices.customer_id`.
 *
 * PostgreSQL folded the uppercase name DBAL generates for the foreign key
 * (`IDX_6A2F2F95A5A280D`) to lower case when the table was created. On
 * introspection DBAL derives the same identifier again and then also finds the
 * stored index, which made `doctrine:schema:validate` (and anything that
 * introspects the schema) abort with "An index with name ... was already
 * defined". A name DBAL does not generate cannot collide with it.
 *
 * The index itself is kept: it is what makes the `ON DELETE RESTRICT` check and
 * customer-scoped reads cheap.
 */
final class Version20261117180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename idx_6a2f2f95a5a280d to idx_invoices_customer so schema introspection cannot collide.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_6a2f2f95a5a280d RENAME TO idx_invoices_customer');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_invoices_customer RENAME TO idx_6a2f2f95a5a280d');
    }
}
