<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves identity from an external OIDC issuer/subject pair to a local email
 * address plus password hash, and adds the single-use token table.
 *
 * Existing rows keep their identity: the subject becomes the email where the
 * provider supplied one, and a deterministic `legacy-<uuid>@invalid.local`
 * address is used where it did not. Those rows have no password, so they can
 * only be claimed through the password-reset flow.
 */
final class Version20261116103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace OIDC identity with native email + password authentication and add auth_tokens.';
    }

    public function up(Schema $schema): void
    {
        // 1. The issuer/subject pair is no longer the identity.
        $this->addSql('DROP INDEX uniq_principals_issuer_subject');
        $this->addSql('ALTER TABLE principals DROP issuer');
        $this->addSql('ALTER TABLE principals DROP subject');

        // 2. Email becomes the identity: fill the gaps, normalize, then enforce.
        $this->addSql("UPDATE principals SET email = 'legacy-' || id || '@invalid.local' WHERE email IS NULL");
        $this->addSql('UPDATE principals SET email = lower(email)');
        $this->addSql('ALTER TABLE principals ALTER email SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_principals_email ON principals (email)');

        // 3. Credential and lifecycle columns.
        $this->addSql('ALTER TABLE principals ADD password_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE principals ADD email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE principals ADD last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // 4. Copy the boolean flag before dropping it.
        $this->addSql('UPDATE principals SET email_verified_at = now() WHERE email_verified = true');
        $this->addSql('ALTER TABLE principals DROP email_verified');

        // 5. Single-use tokens for email verification and password reset.
        $this->addSql('CREATE TABLE auth_tokens (id UUID NOT NULL, principal_id UUID NOT NULL, type VARCHAR(16) NOT NULL, token_hash CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_auth_tokens_token_hash ON auth_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_auth_tokens_principal_type ON auth_tokens (principal_id, type)');
        $this->addSql('ALTER TABLE auth_tokens ADD CONSTRAINT FK_AUTH_TOKENS_PRINCIPAL FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE auth_tokens ADD CONSTRAINT CHK_AUTH_TOKENS_TYPE CHECK (type IN ('verify', 'reset'))");
    }

    public function down(Schema $schema): void
    {
        // Reverses the schema exactly. Password hashes, verification timestamps
        // and login timestamps are destroyed by the forward migration and
        // cannot be restored; issuer/subject are rebuilt from the email so the
        // table still satisfies the old model.
        $this->addSql('DROP TABLE auth_tokens');

        $this->addSql('ALTER TABLE principals ADD email_verified BOOLEAN DEFAULT NULL');
        $this->addSql('UPDATE principals SET email_verified = (email_verified_at IS NOT NULL)');
        $this->addSql('ALTER TABLE principals DROP last_login_at');
        $this->addSql('ALTER TABLE principals DROP email_verified_at');
        $this->addSql('ALTER TABLE principals DROP password_hash');

        $this->addSql('DROP INDEX uniq_principals_email');
        $this->addSql('ALTER TABLE principals ALTER email DROP NOT NULL');

        $this->addSql('ALTER TABLE principals ADD issuer VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE principals ADD subject VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE principals SET issuer = 'restored://legacy', subject = COALESCE(email, 'legacy-' || id || '@invalid.local')");
        $this->addSql('ALTER TABLE principals ALTER issuer SET NOT NULL');
        $this->addSql('ALTER TABLE principals ALTER subject SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_principals_issuer_subject ON principals (issuer, subject)');
    }
}
