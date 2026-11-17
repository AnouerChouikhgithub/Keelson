# Keelson roadmap

Nine sprints from repository cleanup to a tagged release. Statuses describe what is
verified in this repository; they are not marketing claims.

| # | Sprint | Scope | Status |
| --- | --- | --- | --- |
| 1 | Cleanup | Delete dead files, mocks and duplicated types; tidy the CSS and the backend dead code; merge the docs into `README.md` and this roadmap; get `.gitignore` right. No behaviour change. | **Done** |
| 2 | Native auth backend | First-party authentication in the Symfony API: password storage, register/verify/login/logout/forgot/reset, CSRF token in the session payload, rate limiting, server-side sessions. | **Done** |
| 3 | Auth frontend | Sign-in, registration, verification and password-reset screens plus session-expiry handling against the native endpoints. | **Done** |
| 4 | Fixtures with logins | Synthetic demo identities that can sign in, with the target guard, idempotency and no-production refusals. | **Done** |
| 5 | Team, invitations, dashboard, notifications backend | Real endpoints for memberships and roles, invitations (issue/accept/revoke), dashboard metrics from invoices, notifications with read state; the customer/invoice/audit API; the SPA stopped using mocks. | **Done** |
| 6 | Isolation + security hardening | Membership voters everywhere, cross-tenant denials as `404`, CSRF and same-origin enforcement, security headers, structured logs with redaction, the production configuration validator, and the regression tests that lock each rule in. Row-level security was **not** implemented — see [adr/0003](adr/0003-postgresql-row-level-security.md). | **Done, with a documented gap** |
| 7 | Frontend completion | Every screen in `docs/ui-spec.md` on real data, i18n (EN/FR/AR, RTL), accessibility states, light/dark/system themes. | **Done** |
| 8 | Quality + CI | Component tests (jsdom), PHPStan level 8 with no baseline, php-cs-fixer, the coverage gate, OpenAPI + router parity, Playwright journeys, the bundle budget, a CI workflow with pinned actions, Dependabot, ADRs, and the measured performance work. | **Done** |
| 9 | Final sweep | Dead-code detection with empty findings, repository hygiene and a history secret scan, production packaging (images + compose), the production-build proof, the full documentation set, the acceptance run and the `v1.0.0` tag. | **Done** |

## Where 1.0.0 stands

- Backend: the suite runs against a disposable PostgreSQL 16 (counts: `bash bin/test-counts.sh`), PHPStan
  level 8 with the dead-code detector reporting nothing, php-cs-fixer clean.
- Frontend: node and jsdom projects, `tsc -b`, ESLint,
  `vite build`, a bundle budget, `knip` and the dictionary scan all clean.
- Browser: seven Playwright journeys over the real stack pass locally.
- Packaging: both images build and the compose stack serves the app with HSTS only
  over HTTPS, verified against a fresh production database.
- Coverage: the 80% gate for `src/Service`, `src/Security` and `src/Controller`
  exists and runs in CI; **this machine has no `pcov`/`xdebug`, so the number was
  not measured locally**.

Known gaps are listed in [limitations.md](limitations.md).
