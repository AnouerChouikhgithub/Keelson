# ADR 0001 — Session cookies for authentication

- **Status:** accepted
- **Date:** 2026-11-16

## Context

The SPA and the API are served from one origin (Vite proxies `/api` in
development; a reverse proxy does it in production). The product is a
multi-tenant dashboard, not a public API for third-party clients. The backend is
Symfony with `security-bundle`, which already provides a session stack.

Two options were considered: a session cookie managed by Symfony, or a
stateless bearer token (JWT or opaque) kept in the browser.

A bearer token has to be stored somewhere JavaScript can reach, which means an
XSS becomes a stolen credential, and it needs a revocation story of its own.

## Decision

Authenticate with Symfony's server-side session, transported in an `HttpOnly`,
`SameSite=Lax`, `Secure`-when-HTTPS cookie. Sessions are stored in PostgreSQL
through `PdoSessionHandler`, so they survive a deploy and are shared by every
worker.

Every unsafe request must also carry `X-CSRF-Token`, taken from the `csrfToken`
field of `GET /api/session`, plus a same-origin `Origin` header. The token lives
in memory in the SPA only; it is never written to `localStorage`.

## Consequences

- JavaScript cannot read the credential, so an XSS cannot exfiltrate the session.
- CSRF becomes a real risk and is handled explicitly: origin check first, then the
  token, and `403 csrf_invalid` on failure (see `CsrfProtectionListener`).
- The API is same-origin only. A cross-origin client would need a documented CORS
  allow-list and its own token flow; `VITE_API_BASE_URL` exists for that but is not
  used today.
- Sessions are database rows, so a session-store outage is an authentication
  outage. `/readyz` checks the database for that reason.
- Signing out everywhere is a database delete rather than a token-blocklist.
