---
id: invoice-lifecycle
title: Invoices
roles: []
---

An invoice starts as a draft: a customer, an issue date, a due date on or after it, and one or more lines with a description, a quantity and a unit price. Tax rate and discount are optional. The server computes subtotal, tax, discount and total; the currency is TND.

Numbers are allocated per organization and year, so the first invoice of 2026 is `2026-000001`. Allocation is serialised, which is why two people creating an invoice at the same moment do not receive the same number.

Draft: editable and deletable. Send: the status becomes `sent`. Mark paid: allowed from `sent` or `overdue`, and it notifies the rest of the administration.

Overdue is derived, not stored: a sent invoice whose due date has passed reads as overdue, and a read never writes the status.

Owner, Admin and Member may create, edit, send and mark paid; only Owner and Admin may delete a draft. A Viewer sees the list and the detail. Every change appends an audit event in the same transaction as the change.
