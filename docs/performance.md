# Performance: list endpoints at a realistic size

Measured on 2026-11-16 against `keelson_dev` on this development machine
(Windows, PHP 8.5.12 CLI, PostgreSQL 16 in Docker, loopback). Nothing here is a
production benchmark: it is a same-machine before/after comparison, and the
absolute HTTP numbers are dominated by the development server's per-request boot
(see [HTTP latency](#http-latency-p50p95) below).

## Method

```bash
cd backend
bash bin/dev-reset.sh                       # keelson_dev: schema + the 30-invoice fixture set
php bin/console app:fixtures:bulk --force   # 1,000 customers, 10,000 invoices, 10,000 audit events
php -S 127.0.0.1:8000 -t public public/index.php
php bin/perf.php --requests=20              # p50/p95 per endpoint, signed in as owner@example.test
```

`app:fixtures:bulk` is dev/test only and refuses the same targets as
`app:fixtures:load` (production, non-PostgreSQL, non-loopback host, or a database
whose name does not look local). It writes through DBAL in batches of 500, so
the whole set lands in **4.8 s**:

```
 ---------------------- ----------- --------
  Record                 Added now   Target
 ---------------------- ----------- --------
  customers              1000        1000
  invoices               10000       10000
  invoice audit events   10000       10000
 ---------------------- ----------- --------
 [OK] Bulk data ready in keelson_dev@127.0.0.1 in 4.8 s.
```

Dataset after seeding: 12 customers (fixtures) + 1,000 bulk, 30 + 10,000 invoices,
and 10,000 audit events, all in one organization.

Plans were captured with `EXPLAIN (ANALYZE, BUFFERS)` on statements that mirror
the repository DQL (same joins, same `ORDER BY … DESC, id DESC`, same
`LIMIT 51`). `pg_trgm` availability was checked first:

```
  name      default_version   installed_version
  pg_trgm   1.6
```

## Database execution time

| Query | Before (ms) | After (ms) |
| --- | --- | --- |
| customers list (cursor page) | 2.8 | 1.1 |
| customers search (`q`) | 2.8 | 1.2 |
| **invoices list with `q`** | **75.9** | **13.5** |
| audit list | 2.4 | 1.9 |
| dashboard aggregates | 9.1 | 11.3 |
| dashboard revenue by month | 7.8 | 6.2 |
| members list | 0.5 | 0.8 |

Read that table conservatively: the only structural change was the trigram
migration, and the drop from 75.9 to 13.5 ms on the invoice search is **mostly
buffer-cache warming between the first and second run**, not the new indexes —
the second plan still shows a sequential scan (see below). The before run
included cold reads from the Docker volume.

## What was already indexed

The cursor pagination shipped with composite keyset indexes, so those pages were
never the problem:

- `idx_customers_organization_created_id` — `customers (organization_id, created_at DESC, id DESC)`
- `idx_invoices_organization_created_id` — `invoices (organization_id, created_at DESC, id DESC)`
- `idx_invoice_audit_events_organization_created_id` — `invoice_audit_events (organization_id, created_at DESC, id DESC)`

A `LIMIT 51` page is an index scan of 51 rows in every case (2.8 ms → 1.1 ms above
is timing noise).

## What was not: the `q` search

The list queries filter with `LOWER(column) LIKE '%term%'`. A leading wildcard
cannot use a btree index, so `q` was a sequential scan. `Version20261116170000`
adds `pg_trgm` GIN expression indexes:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_customers_name_trgm  ON customers USING gin (lower(name) gin_trgm_ops);
CREATE INDEX idx_customers_email_trgm ON customers USING gin (lower(email) gin_trgm_ops);
CREATE INDEX idx_invoices_number_trgm ON invoices USING gin (lower(number) gin_trgm_ops);
```

They are usable and fast on their own — with the planner's sequential-scan option
disabled to show what it would do at a larger size:

| Statement | Plan | Time |
| --- | --- | --- |
| `customers WHERE lower(name) LIKE '%bulk customer 001%'` | Bitmap Index Scan on `idx_customers_name_trgm` | 2.1 ms |
| `customers WHERE lower(email) LIKE '%bulk-0001%'` | Bitmap Index Scan on `idx_customers_email_trgm` | 0.7 ms |
| `invoices WHERE lower(number) LIKE '%100001%'` | Bitmap Index Scan on `idx_invoices_number_trgm` | 0.6 ms |

At 10k rows PostgreSQL still prefers the sequential scan because that is cheaper
here; the indexes pay off as the tenant grows.

**Known limitation.** The invoice list searches two tables in one predicate
(`invoices.number` OR `customers.name`), so no single index can serve it: the plan
joins every invoice of the tenant and filters afterwards. The honest follow-ups,
neither implemented in 1.0.0, are:

1. a stored generated column `customer_name` on `invoices` derived from the
   snapshot, with a trigram index, so both sides of the `OR` live on one table; or
2. splitting `q` into a `UNION` of two indexed branches.

## HTTP latency (p50/p95)

20 sequential requests per endpoint, signed in as `owner@example.test`:

| Endpoint | Before p50 / p95 (ms) | After p50 / p95 (ms) |
| --- | --- | --- |
| customers list | 576.8 / 668.4 | 633.7 / 834.0 |
| invoices list with `q` | 609.7 / 808.7 | 651.0 / 1819.6 |
| audit list | 574.1 / 708.7 | 533.7 / 835.7 |
| dashboard | 617.0 / 772.4 | 677.9 / 803.1 |
| members list | 566.5 / 757.9 | 612.8 / 847.2 |

**The 100 ms p95 target is not met on this host, and the numbers say why.**
`GET /healthz` — a controller that reads nothing, touches no database and returns
two fields — measures **p50 285.7 ms / p95 475.2 ms** on the same server. That is
Symfony's per-request boot plus the PHP CLI server on Windows, paid before any
controller runs. The measured database work is 1–20 ms, an order of magnitude
below the budget; the HTTP figure is the development server, not the application.

So this environment can show a query cost but not an end-to-end latency. A real
measurement needs php-fpm with opcache and a warm worker (or the container image
built in `docs/deployment.md`), which is where 100 ms becomes reachable. The
second run is also visibly noisier than the first, because the machine was busy
with the Playwright browser download — treat the p95 column as indicative only.
