<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-scoped customer and invoice lifecycle data with invoice audit events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customers (id UUID NOT NULL, organization_id UUID NOT NULL, name VARCHAR(160) NOT NULL, email VARCHAR(320) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, billing_address JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_customers_organization_id_id ON customers (organization_id, id)');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_CUSTOMERS_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE invoices (id UUID NOT NULL, organization_id UUID NOT NULL, customer_id UUID NOT NULL, number VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL, customer_snapshot JSON NOT NULL, issue_date DATE NOT NULL, due_date DATE NOT NULL, line_items JSON NOT NULL, tax_rate NUMERIC(7,4) DEFAULT NULL, discount_amount NUMERIC(14,3) DEFAULT NULL, subtotal NUMERIC(14,3) NOT NULL, tax_amount NUMERIC(14,3) NOT NULL, total NUMERIC(14,3) NOT NULL, currency VARCHAR(3) NOT NULL, created_by_principal_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoices_organization_number ON invoices (organization_id, number)');
        $this->addSql('CREATE INDEX idx_invoices_organization_status_issue ON invoices (organization_id, status, issue_date)');
        $this->addSql('CREATE INDEX idx_invoices_organization_created ON invoices (organization_id, created_at, id)');
        $this->addSql('CREATE INDEX IDX_6A2F2F95A5A280D ON invoices (customer_id)');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_INVOICES_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_INVOICES_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_INVOICES_CUSTOMER_TENANT FOREIGN KEY (organization_id, customer_id) REFERENCES customers (organization_id, id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_INVOICES_CREATOR FOREIGN KEY (created_by_principal_id) REFERENCES principals (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("ALTER TABLE invoices ADD CONSTRAINT CHK_INVOICES_STATUS CHECK (status IN ('draft', 'sent', 'overdue', 'paid'))");
        $this->addSql("ALTER TABLE invoices ADD CONSTRAINT CHK_INVOICES_CURRENCY CHECK (currency = 'TND')");
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT CHK_INVOICES_DATE_RANGE CHECK (due_date >= issue_date)');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT CHK_INVOICES_NONNEGATIVE CHECK (subtotal >= 0 AND tax_amount >= 0 AND total >= 0 AND (discount_amount IS NULL OR discount_amount >= 0) AND (tax_rate IS NULL OR tax_rate >= 0))');

        $this->addSql('CREATE TABLE invoice_audit_events (id UUID NOT NULL, organization_id UUID NOT NULL, invoice_id UUID DEFAULT NULL, customer_id UUID DEFAULT NULL, action VARCHAR(32) NOT NULL, actor_principal_id UUID NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_invoice_audit_organization_created ON invoice_audit_events (organization_id, created_at)');
        $this->addSql('CREATE INDEX idx_invoice_audit_invoice_created ON invoice_audit_events (invoice_id, created_at)');
        $this->addSql('ALTER TABLE invoice_audit_events ADD CONSTRAINT FK_INVOICE_AUDIT_ORGANIZATION FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_audit_events ADD CONSTRAINT FK_INVOICE_AUDIT_ACTOR FOREIGN KEY (actor_principal_id) REFERENCES principals (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql("CREATE FUNCTION prevent_invoice_audit_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'invoice audit events are append-only'; END; $$ LANGUAGE plpgsql");
        $this->addSql('CREATE TRIGGER invoice_audit_events_append_only BEFORE UPDATE OR DELETE ON invoice_audit_events FOR EACH ROW EXECUTE FUNCTION prevent_invoice_audit_mutation()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER invoice_audit_events_append_only ON invoice_audit_events');
        $this->addSql('DROP FUNCTION prevent_invoice_audit_mutation()');
        $this->addSql('DROP TABLE invoice_audit_events');
        $this->addSql('ALTER TABLE invoices DROP CONSTRAINT FK_INVOICES_ORGANIZATION');
        $this->addSql('ALTER TABLE invoices DROP CONSTRAINT FK_INVOICES_CUSTOMER');
        $this->addSql('ALTER TABLE invoices DROP CONSTRAINT FK_INVOICES_CUSTOMER_TENANT');
        $this->addSql('ALTER TABLE invoices DROP CONSTRAINT FK_INVOICES_CREATOR');
        $this->addSql('DROP TABLE invoices');
        $this->addSql('ALTER TABLE customers DROP CONSTRAINT FK_CUSTOMERS_ORGANIZATION');
        $this->addSql('DROP TABLE customers');
    }
}
