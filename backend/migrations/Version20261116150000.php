<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security trail for sign-in, password and membership events.
 *
 * Deliberately has no foreign key to principals: deleting an account must not
 * erase the record that it signed in, or that it was removed from an
 * organization. The id is kept as a plain UUID for the same reason.
 */
final class Version20261116150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the security_events audit trail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE security_events (id UUID NOT NULL, principal_id UUID DEFAULT NULL, type VARCHAR(64) NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, request_id VARCHAR(64) DEFAULT NULL, data JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_security_events_type_created ON security_events (type, created_at)');
        $this->addSql('CREATE INDEX idx_security_events_principal_created ON security_events (principal_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE security_events');
    }
}
