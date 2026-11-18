<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the counter that lets a password change end the sessions opened with the
 * old password.
 *
 * The column is an integer rather than a timestamp on purpose: comparing two
 * integers cannot drift with clock skew between web workers, and the only
 * question asked of it is "is this the value the session recorded?".
 *
 * `DEFAULT 0 NOT NULL` backfills existing accounts in the same statement, so the
 * migration is safe on a database that already has users: every session in flight
 * adopts version 0 on its next request and is then subject to the normal check.
 */
final class Version20261202120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add principals.session_version so a password change can end open sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE principals ADD session_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE principals DROP session_version');
    }
}
