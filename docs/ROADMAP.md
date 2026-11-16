# Keelson roadmap

Nine sprints from repository cleanup to a final sweep. Statuses describe what is
verified in this repository today; they are not readiness claims for production.

| # | Sprint | Scope | Status |
| --- | --- | --- | --- |
| 1 | Cleanup | Delete dead files, mocks and duplicated types; tidy the CSS and the backend dead code; merge the docs into `README.md` and this roadmap; get `.gitignore` right. No behaviour change. | **Done** |
| 2 | Native auth backend | Replace the provider session with first-party authentication in the Symfony API: password storage, register/verify/login/logout/forgot/reset endpoints, CSRF token in the session payload, rate limiting and the provider migration. | Done |
| 3 | Auth frontend | Sign-in, registration, email-verification and password-reset screens plus session-expiry handling against the native endpoints, and removal of the provider copy from the SPA. | Not started |
| 4 | Fixtures with logins | Extend `backend/src/DataFixtures/` so the synthetic demo identities can actually sign in, keeping the target guard, idempotency and no-production refusals. | Not started |
| 5 | Team, invitations, dashboard, notifications backend | Real endpoints for memberships and roles, invitation issue/accept/revoke, dashboard metrics read from invoices, and notifications with read state. Replaces the remaining `frontend/src/mocks/` surfaces. | Not started |
| 6 | Isolation + security hardening | PostgreSQL row-level security (or an equivalent defence in depth), CSRF token exposure for API clients, authorization audit, and the regression tests that lock each rule in. Application-layer scoping alone is the current state. | Not started |
| 7 | Frontend completion | Finish the screens in `docs/ui-spec.md`, i18n and accessibility states included, on real data only. | Not started |
| 8 | Quality + CI | Component/DOM tests, coverage gates, a green hosted CI run, dependency-advisory policy, and the `doctrine:schema:validate` index-name collision. | Not started |
| 9 | Final sweep | Remove what the previous sprints left behind, reconcile this roadmap and every document with the shipped code, and record the final verification evidence. | Not started |

## Verified baseline (2026-11-16, Sprint 1)

- Frontend: `pnpm run build`, `pnpm run lint` (0 findings) and `pnpm test`
  (**57 tests**, 5 files) exit 0.
- Backend: `composer validate --no-check-publish` and
  `php bin/console lint:container --env=test` exit 0;
  `php bin/phpstan analyse` (level 5, baseline) reports no errors.
- Backend test suite against `backend/bin/test-db.sh`:
  **115 tests / 585 assertions, 4 failures**. The four failures pre-date Sprint 1
  and are unchanged by it: anonymous `/api/...` requests now answer `401` JSON
  from `ApiAuthenticationEntryPoint`, while four tests still expect a redirect to
  `/login`. Fixing that is behaviour work, not cleanup.
- Not established: PostgreSQL RLS, real provider sign-in (until Sprint 2/3), any
  billing or usage enforcement, invitation delivery.
