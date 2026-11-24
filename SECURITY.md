# Security policy

## Reporting a vulnerability

Do **not** open a public issue for a security problem. Send a private report to the
repository owner (the address on the account that owns this repository) with:

- what you can do that you should not be able to do,
- the request or page that does it, with the smallest reproduction you have,
- the version or commit you tested, and whether it reproduces on a clean database.

You will get an acknowledgement within three working days. Please give the owner
time to ship a fix before publishing anything: the usual window is 30 days for a
low-severity issue and 7 days for a critical one.

## Supported versions

| Version | Supported |
| --- | --- |
| 1.0.x | yes — fixes are made on `main` and tagged here |
| < 1.0 | no — the sprints before 1.0 were developed in the open on `main` |

There are no long-term-support branches. A fix goes to `main`, and a release is a
new tag.

## What the application already does

Useful to know before reporting — these are deliberate:

- Sessions are server-side rows; the cookie is `HttpOnly`, `SameSite=Lax` and
  `Secure` whenever the request is HTTPS. JavaScript cannot read the credential.
- Every unsafe request must carry a same-origin `Origin` **and** the
  `X-CSRF-Token` from `GET /api/session`. Failures are `403 csrf_invalid`.
- Cross-tenant access answers `404`, never `403`, so tenant existence is not
  disclosed. A `403` means the request itself was refused (CSRF, unverified
  address), not a missing permission.
- Sign-in is rate limited per address and per client address, and answers the same
  message for an unknown address and a wrong password.
- An unconfirmed address cannot sign in (`403 email_not_verified`); the check runs
  after the password is verified so it cannot be used to enumerate accounts.
- Reset and verification tokens are single-use, stored hashed, and expire.
- Secrets are never logged; the structured logger redacts known keys, and
  `app:config:check` reports configuration problems without printing values.
- Secrets live in the ignored `backend/.env.local` (or the deployment's secret
  store); tracked `.env` files carry empty placeholders. Diagnostic commands are not
  all redacted — `debug:dotenv` prints environment values, so never run it in CI, a
  shared terminal or a pasted log. `bin/secret-scan.sh` (a CI step) fails on a tracked
  file that carries a value; the rotation checklist for the one credential that reached
  history is `docs/runbooks/secret-rotation.md`.

## Known limitations that are not vulnerabilities

These are documented gaps, not bugs to report:

- row-level security covers the tenant tables — `customers`, `invoices`,
  `invitations`, `notifications`, `organization_ai_settings` and `ai_requests` —
  and the session's organization is set per transaction; the **identity** tables
  (`principals`, `memberships`, sessions) are still scoped in the application. A
  missing policy therefore fails open on those tables and the repository layer is
  the only guard there,
- no multi-factor authentication,
- no email-change flow,
- no CSP nonce; `style-src 'unsafe-inline'` is required by inline style attributes.

## Dependencies

`composer audit` and `pnpm audit --audit-level high` run on every push and fail the
build; Dependabot opens grouped upgrade pull requests weekly. A new advisory is
therefore a build failure, and the fix is an upgrade or a documented exception in
the workflow.
