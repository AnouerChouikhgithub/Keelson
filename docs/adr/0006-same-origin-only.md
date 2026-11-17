# ADR 0006 — The SPA and the API are served from one origin

- **Status:** accepted
- **Date:** 2026-11-17

## Context

Session cookie authentication makes the browser the security boundary: the
cookie is `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS, and every unsafe
request must also carry `X-CSRF-Token` plus a same-origin `Origin` header
(`App\EventListener\CsrfProtectionListener`).

Two deployment shapes are possible:

1. **Same origin** — one nginx serves the SPA and forwards `/api`, `/healthz`
   and `/readyz` to php-fpm. The session cookie is first-party, `Origin` equals
   the scheme and host the request arrived on, and no CORS headers are needed.
2. **Cross origin** — the SPA is built for one host and calls an API on another.
   The API would then need an explicit CORS allow-list with credentials, the
   cookie would need `SameSite=None; Secure`, and the CSRF/origin check would
   have to accept the SPA's origin explicitly.

The repository previously exposed `VITE_API_BASE_URL` (and a Docker build
argument of the same name) as if the second shape worked, but the backend has
never sent `Access-Control-Allow-Origin`, and `frontend/nginx.conf` forwards
`/api` on its own origin anyway. Setting that variable produced a build whose
every API call the browser refused — a documented feature that could not work.

## Decision

Support **one** shape: same origin, through the reverse proxy in
`frontend/nginx.conf`. Concretely:

- `frontend/src/api/client.ts` requests relative paths with
  `credentials: 'same-origin'`; there is no configurable API base URL.
- `frontend/Dockerfile` no longer takes a `VITE_API_BASE_URL` build argument.
- No CORS bundle, headers or preflight handling is added.
- `docs/deployment.md` documents the single shape and states that cross-origin
  is not supported.

`KEELSON_BACKEND_ORIGIN` remains a **development-only** Vite proxy target: the
browser still talks to the Vite server on one origin, and Vite forwards `/api`.

## Consequences

- One origin, one cookie policy, one CSRF rule; the origin check compares against
  the request's own scheme and host, so it cannot be satisfied by an attacker's
  site.
- A deployment that genuinely needs two hosts must add CORS deliberately: an
  allow-list, `SameSite=None; Secure`, an explicit trusted-origin check in the
  CSRF listener, and a client-side base URL. That is a feature with its own
  tests, not a configuration flag — ADR 0001 records the same reasoning.
- Static hosting of `dist/` on a different host than the API is not supported
  until that work is done.
