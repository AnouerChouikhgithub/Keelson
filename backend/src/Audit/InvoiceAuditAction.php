<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * The closed set of actions an audit event can carry.
 *
 * The audit screen shows a localized label per action, and
 * `frontend/src/i18n/audit-labels.test.ts` parses this class and fails when any
 * action lacks a label in English, French or Arabic. So a new action is
 * deliberately a two-file change: the constant here, the three labels there.
 *
 * Values are stable identifiers stored in `invoice_audit_events.action`; they are
 * never shown to a user unlocalized except as a secondary line next to the label.
 */
final class InvoiceAuditAction
{
    public const CUSTOMER_CREATED = 'customer.created';
    public const CUSTOMER_UPDATED = 'customer.updated';
    public const CUSTOMER_DELETED = 'customer.deleted';
    public const INVOICE_CREATED = 'invoice.created';
    public const INVOICE_UPDATED = 'invoice.updated';
    public const INVOICE_DELETED = 'invoice.deleted';
    public const INVOICE_SENT = 'invoice.sent';
    public const INVOICE_PAID = 'invoice.paid';
}
