<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications, plus the indexes the cursor lists and the dashboard need.
 *
 * Each index mirrors one query shape introduced in this sprint:
 *
 *  - `notifications (principal_id, read_at, created_at DESC)` is the inbox page,
 *    including the unread filter;
 *  - `customers`/`invoices`/`invoice_audit_events (organization_id, created_at DESC,
 *    id DESC)` are the keyset pages ordered by (created_at, id);
 *  - `invoices (organization_id, due_date)` and `(organization_id, paid_at)` back
 *    the dashboard's overdue and revenue aggregates;
 *  - the partial unique index on pending invitations closes the race between two
 *    admins inviting the same address at the same time (the service checks first,
 *    the index is the authority).
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications and the indexes for cursor pagination and dashboard aggregates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (id UUID NOT NULL, principal_id UUID NOT NULL, organization_id UUID DEFAULT NULL, type VARCHAR(32) NOT NULL, data JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_notifications_principal_read_created ON notifications (principal_id, read_at, created_at DESC)');
        $this->addSql('CREATE INDEX idx_notifications_organization ON notifications (organization_id)');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_NOTIFICATIONS_PRINCIPAL FOREIGN KEY (principal_id) REFERENCES principals (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_NOTIFICATIONS_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE notifications ADD CONSTRAINT CHK_NOTIFICATIONS_TYPE CHECK (type IN ('invoice_overdue', 'invoice_paid', 'invitation_accepted', 'member_joined'))");

        $this->addSql('CREATE INDEX idx_customers_organization_created_id ON customers (organization_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_invoices_organization_created_id ON invoices (organization_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_invoices_organization_due_date ON invoices (organization_id, due_date)');
        $this->addSql('CREATE INDEX idx_invoices_organization_paid_at ON invoices (organization_id, paid_at) WHERE paid_at IS NOT NULL');
        $this->addSql('CREATE INDEX idx_invoice_audit_events_organization_created_id ON invoice_audit_events (organization_id, created_at DESC, id DESC)');
        $this->addSql("CREATE UNIQUE INDEX uniq_invitations_pending_email ON invitations (organization_id, lower(email)) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_invitations_pending_email');
        $this->addSql('DROP INDEX idx_invoice_audit_events_organization_created_id');
        $this->addSql('DROP INDEX idx_invoices_organization_paid_at');
        $this->addSql('DROP INDEX idx_invoices_organization_due_date');
        $this->addSql('DROP INDEX idx_invoices_organization_created_id');
        $this->addSql('DROP INDEX idx_customers_organization_created_id');
        $this->addSql('DROP TABLE notifications');
    }
}
