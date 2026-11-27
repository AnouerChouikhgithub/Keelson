---
id: customers
title: Customers
roles: []
keywords: ["customer","client","create customer","add customer","edit customer","delete customer","search customer","contact","billing address","parameter q"]
---

A customer belongs to exactly one organization and is only reachable through it: a customer id from another organization answers as not found.

Create a customer with a name; email, phone and billing address are optional. Owner, Admin and Member may create, edit and delete; a Viewer may read.

The list supports `q`, which matches name or email without regard to case, and cursor pagination, 25 rows per page by default (`limit` up to 100). The cursor is the last row you received, not an offset, so a customer created while you page does not shift the window.

Deleting is refused while an invoice references the customer (`409`, "Customer is referenced by invoices"). The customer row is locked for the check, so an invoice created at the same moment cannot slip through.

Every create, update and delete writes an audit event in the same transaction as the change. An invoice keeps a snapshot of the customer details, so renaming a customer later does not rewrite an issued invoice.
