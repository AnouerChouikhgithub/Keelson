# ADR 0005 — Keyset (cursor) pagination, not offset pages

- **Status:** accepted
- **Date:** 2026-11-16

## Context

Customers, invoices and audit events are lists that grow while they are being
read: an invoice is created between page 1 and page 2. `LIMIT/OFFSET` shifts the
window in that case, so a row can be shown twice or skipped entirely, and the cost
of a deep offset grows with the table.

## Decision

Paginate by keyset: order by `created_at DESC, id DESC`, and return
`nextCursor` = base64url of `{id, createdAt}` for the last row of the page. The
next request resumes strictly after that row. `limit` defaults to 25 and is
clamped to 100.

Supporting indexes `(organization_id, created_at DESC, id DESC)` exist on all
three tables (`Version20261116120000`), so a page is an index scan of at most
`limit + 1` rows.

## Consequences

- A page cannot skip or repeat a row when new rows are inserted; the "newest
  first" window simply starts where the reader stopped.
- Deep pages cost the same as shallow ones.
- The cursor is opaque to clients, which means the sort order cannot be changed
  per request without a new cursor format. Only "newest first" is offered today.
- Jumping to an arbitrary page number is not possible. The UI loads forward with
  "Load more" and filters client-side over the loaded page, stating that the
  results are partial when `nextCursor` is not null.
- A filter or search change resets pagination by starting from no cursor.
