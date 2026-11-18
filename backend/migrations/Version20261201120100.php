<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the AI spend ledger. It stores no prompt and no answer text: tenant content
 * must not be retained just to count tokens.
 */
final class Version20261201120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ai_requests, the content-free AI spend ledger, indexed by organization.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_requests (
            id UUID NOT NULL,
            organization_id UUID NOT NULL,
            principal_id UUID DEFAULT NULL,
            feature VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            model VARCHAR(120) DEFAULT NULL,
            prompt_tokens INT DEFAULT 0 NOT NULL,
            completion_tokens INT DEFAULT 0 NOT NULL,
            total_tokens INT DEFAULT 0 NOT NULL,
            latency_ms INT DEFAULT NULL,
            error_code VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_ai_requests_organization_created ON ai_requests (organization_id, created_at)');
        $this->addSql('CREATE INDEX idx_ai_requests_principal ON ai_requests (principal_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_requests');
    }
}
