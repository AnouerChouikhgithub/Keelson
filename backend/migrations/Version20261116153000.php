<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Server-side session storage.
 *
 * Keeping sessions in PostgreSQL means a session survives a PHP process and is
 * shared by every web worker; the previous filesystem handler tied a signed-in
 * person to one machine. The id is rotated on login and deleted on logout by
 * Symfony, and the lifetime column lets the handler expire rows itself.
 */
final class Version20261116153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the sessions table for PdoSessionHandler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY(sess_id))');
        $this->addSql('CREATE INDEX idx_sessions_lifetime ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
