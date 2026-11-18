<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the per-organization AI opt-in and its monthly token counters.
 *
 * The counters live on this row because the budget is enforced by one conditional
 * UPDATE; that statement needs a single row PostgreSQL can lock.
 */
final class Version20261201120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organization_ai_settings with its opt-in and monthly token counters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization_ai_settings (
            id UUID NOT NULL,
            organization_id UUID NOT NULL,
            enabled BOOLEAN DEFAULT FALSE NOT NULL,
            monthly_token_budget INT DEFAULT 0 NOT NULL,
            period VARCHAR(7) NOT NULL,
            used_tokens INT DEFAULT 0 NOT NULL,
            reserved_tokens INT DEFAULT 0 NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_organization_ai_settings_organization ON organization_ai_settings (organization_id)');
        $this->addSql('ALTER TABLE organization_ai_settings ADD CONSTRAINT fk_organization_ai_settings_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization_ai_settings DROP CONSTRAINT fk_organization_ai_settings_organization');
        $this->addSql('DROP TABLE organization_ai_settings');
    }
}
