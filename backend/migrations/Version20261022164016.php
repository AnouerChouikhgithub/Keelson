<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022164016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invitations (id UUID NOT NULL, email VARCHAR(320) NOT NULL, role VARCHAR(16) NOT NULL, status VARCHAR(64) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, organization_id UUID NOT NULL, invited_by_principal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invitations_organization_status ON invitations (organization_id, status)');
        $this->addSql('CREATE INDEX idx_invitations_email_status ON invitations (email, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invitations_token_hash ON invitations (token_hash)');
        $this->addSql('CREATE INDEX IDX_232710AE32C8A3DE ON invitations (organization_id)');
        $this->addSql('CREATE INDEX IDX_232710AE641013E ON invitations (invited_by_principal_id)');
        $this->addSql('CREATE TABLE memberships (id UUID NOT NULL, role VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, organization_id UUID NOT NULL, principal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_memberships_principal_status ON memberships (principal_id, status)');
        $this->addSql('CREATE INDEX idx_memberships_organization_role ON memberships (organization_id, role)');
        $this->addSql('CREATE UNIQUE INDEX uniq_memberships_organization_principal ON memberships (organization_id, principal_id)');
        $this->addSql('CREATE INDEX IDX_865A477632C8A3DE ON memberships (organization_id)');
        $this->addSql('CREATE INDEX IDX_865A4776474870EE ON memberships (principal_id)');
        $this->addSql('CREATE TABLE organizations (id UUID NOT NULL, name VARCHAR(120) NOT NULL, subdomain VARCHAR(63) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_organizations_status ON organizations (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_organizations_subdomain ON organizations (subdomain)');
        $this->addSql('CREATE TABLE principals (id UUID NOT NULL, issuer VARCHAR(512) NOT NULL, subject VARCHAR(255) NOT NULL, display_name VARCHAR(255) DEFAULT NULL, email VARCHAR(320) DEFAULT NULL, email_verified BOOLEAN DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_principals_issuer_subject ON principals (issuer, subject)');
        $this->addSql('ALTER TABLE invitations ADD CONSTRAINT FK_232710AE32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitations ADD CONSTRAINT FK_232710AE641013E FOREIGN KEY (invited_by_principal_id) REFERENCES principals (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE memberships ADD CONSTRAINT FK_865A477632C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE memberships ADD CONSTRAINT FK_865A4776474870EE FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invitations DROP CONSTRAINT FK_232710AE32C8A3DE');
        $this->addSql('ALTER TABLE invitations DROP CONSTRAINT FK_232710AE641013E');
        $this->addSql('ALTER TABLE memberships DROP CONSTRAINT FK_865A477632C8A3DE');
        $this->addSql('ALTER TABLE memberships DROP CONSTRAINT FK_865A4776474870EE');
        $this->addSql('DROP TABLE invitations');
        $this->addSql('DROP TABLE memberships');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE principals');
    }
}
