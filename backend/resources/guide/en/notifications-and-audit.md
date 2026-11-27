---
id: notifications-and-audit
title: Notifications and the audit trail
roles: []
keywords: ["notification","inbox","unread","mark read","audit","audit trail","history","activity log"]
---

Notifications are personal, not organizational: your inbox collects events from every organization you belong to, and only you can read or mark them. The four types are `invoice_paid` (someone else recorded the payment), `invoice_overdue`, `member_joined` and `invitation_accepted`.

The list returns 25 rows by default (`limit` up to 100), newest first, with a cursor for the next page; `unread=1` returns only unread rows. Marking one notification read, or all of them, only changes your own read state.

The audit trail is the organization's history. Any active member may read `/api/organizations/{id}/invoice-audit-events`, filtered by `invoiceId` and searched with `q`, cursor paged like the other lists. Recorded actions are `customer.created`, `customer.updated`, `customer.deleted`, `invoice.created`, `invoice.updated`, `invoice.sent`, `invoice.paid` and `invoice.deleted`, each with the actor, the invoice or customer id and a timestamp.

The table is append-only: a database trigger refuses updates and deletes, so an audit row cannot be rewritten, only added.
