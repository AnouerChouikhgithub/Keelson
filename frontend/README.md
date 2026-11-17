# Frontend (React + Vite + TypeScript)

The Keelson dashboard shell. It is connected to the Symfony API for everything
that has a backend:

- the **session** (`GET /api/session`), including the signed-out and unavailable
  states;
- the caller's **organizations and role** (`GET /api/me/organizations`);
- **customers** — list, read, create, update, delete, including the `409` raised
  when invoices reference a customer;
- **invoices** — list with the server status filter, read, create draft, update
  draft, send, mark paid, delete draft, with `409` handling and server-returned
  totals.

Still mock, clearly labelled, and never mixed into an API-backed list: dashboard
metrics, onboarding and invitations, team, billing, pricing and notifications.
Nothing on those screens leaves the browser except local preferences and
notification read state.

The verified request/response contract is
[`../docs/api-contract.md`](../docs/api-contract.md).

## Stack

- React 19.3.1, React DOM 19.3.1
- Vite 8.3.4, `@vitejs/plugin-react` 6.1.2
- TypeScript 6.0.3 — pinned below 6.1 because `typescript-eslint` 8.71.2 supports
  `>=4.8.4 <6.1.0`
- ESLint 10.13.0 with `typescript-eslint` 8.71.2, `eslint-plugin-react-hooks`,
  `eslint-plugin-react-refresh`
- Vitest 5.1.0 (Node environment — no `jsdom`, no Testing Library)

No routing, i18n or UI component library is installed; navigation, translations
and styles are local code. The API layer is hand-written because the backend has
no OpenAPI document yet.

## Commands

Run from `frontend/`:

```sh
pnpm install --frozen-lockfile   # install exactly what the lockfile pins
pnpm start                       # Vite on 127.0.0.1:5173
pnpm run build                   # tsc -b && vite build → dist/
pnpm run lint                    # eslint .
pnpm test                        # vitest run, Node environment
```

`pnpm start` is a script alias for `vite --host 127.0.0.1`; plain `pnpm dev` binds
only the IPv6 loopback. A running backend is needed for anything past the sign-in
screen — see [`../README.md`](../README.md).

## Configuration

| Variable | Where | Meaning |
| --- | --- | --- |
| `VITE_API_BASE_URL` | build/runtime env, optional | Absolute API origin when the API is not same-origin. Empty by default. **Never** a secret: Vite inlines every `VITE_*` value into the public bundle. |
| `KEELSON_BACKEND_ORIGIN` | dev-server env, optional | Proxy target for `/api` (the only proxied prefix). Defaults to `http://127.0.0.1:8000`. |

The dev proxy uses `changeOrigin: false` on purpose: the session cookie must stay
same-origin, and the API's mutation guard compares the request `Origin` with
`getSchemeAndHttpHost()`. Do not add permissive CORS to work around a proxy
misconfiguration.

## API layer (`src/api/`)

| File | Responsibility |
| --- | --- |
| `client.ts` | The only `fetch` call site. Same-origin credentials, `cache: 'no-store'`, JSON in/out, `204` and empty bodies, non-JSON error pages, network failures, abort passthrough, an HTML success body reported as a misrouted request, the `X-CSRF-Token` header on unsafe methods, and exactly one session re-read plus one replay after `403 csrf_invalid`. |
| `auth.ts` | One function per `/api/auth/*` endpoint (`login`, `register`, `verifyEmail`, `resendVerification`, `forgotPassword`, `resetPassword`, `logout`); `login` returns the parsed session or throws. |
| `errors.ts` | `ApiError` with `kind: 'http' \| 'network' \| 'parse'`, `status`, the server `code`, the per-field `fields` and a sanitized `serverMessage`. |
| `types.ts` | Types mirroring the verified responses, plus the invoice status enum and the shared organization/team shapes. |
| `organizations.ts` | `listOrganizations`, plus `canWriteInvoiceResources` / `canDeleteInvoiceResources`, which mirror `InvoiceVoter` only to hide actions the server would reject. |
| `customers.ts`, `invoices.ts` | One function per endpoint, building paths and payloads. |
| `useApiResource.ts` | Loads one resource, aborts on unmount or loader change, discards stale responses, derives `loading` instead of synchronizing it in an effect. |
| `presentError.ts` | Maps `ApiError` to localized copy, `presentAuthError` for the auth screens (code → sentence, field → local rule) and `isConflict`. |

Session state lives in `src/session/`: `session.ts` parses `/api/session`,
`AuthProvider.tsx` owns `{status, user, csrfToken}` plus `signIn`/`signOut`/`refresh`,
and `AuthContext.ts` exposes it through `useAuth()`. The CSRF token is kept in
memory only — never in `localStorage`, `sessionStorage` or a cookie of our own —
and is handed to `api/client.ts` through `setCsrfToken`, which also installs the
re-read the client uses after a `403 csrf_invalid`. Sign-out is a JSON call to
`POST /api/auth/logout`; there is no provider redirect and no hidden form.

### Session and tenant rules

- `src/AppRouter.tsx` renders a gate until `/api/session` answers (loading, or a
  retryable failure when the API is unreachable), sends an anonymous visitor to
  `/sign-in`, and keeps a signed-in user off the five public auth routes.
- The display name and email come from the session; the organization list and role
  come from `/api/me/organizations`. There is no demo identity in that path.
- The selected organization is a **client-side selection**, not authorization:
  every request carries it in the path and the server re-authorizes it. Switching
  organizations remounts the organization-scoped screens and clears the open
  draft.
- A membership the server no longer reports cannot be selected.

### Error behaviour

| Status | Shown as |
| --- | --- |
| `400`/`422` | the server's message at form level |
| `401` | for sign-in, "the email address or password is incorrect"; elsewhere, a session that ended — the next session read sends the visitor to `/sign-in` |
| `403` | permission denied (the mutation origin/CSRF guard) |
| `404` | "not found" — also what a foreign tenant returns |
| `409` | conflict explanation and a refetch, never a silent overwrite |
| `429` | rate-limited message |
| `5xx` | generic server error; internals are never rendered |
| network | connection message with retry |
| abort | nothing — the superseded request is ignored |

## Localization, account and notifications

- **Languages**: English, French and Arabic, with `lang`/`dir` on `<html>` and
  Arabic rendered right-to-left. Persisted under `keelson-locale`.
- **Dictionaries**: `src/i18n/messages.ts` defines a typed English dictionary;
  French and Arabic are typed as `Messages`, so a missing or renamed key is a
  TypeScript error.
- **Formatting**: `src/i18n/format.ts` formats dates, numbers, percentages and TND
  currency with `Intl`. API amounts are decimal strings, formatted for display and
  never re-summed as authoritative totals.
- **Account menu**: keyboard-accessible (`Escape`, arrows, `Home`/`End`, outside
  click) with My account, Notifications and Settings.
- **Account screen**: real session identity, active organization and role;
  read-only because there is no profile write endpoint.
- **Notifications**: All/Unread filters, mark-one/mark-all, empty state, bell
  badge. Read ids persist under `keelson-notifications-read`; the records
  themselves are mock data.
- **Settings**: appearance reuses `ThemeProvider` (light/dark/system), language
  applies instantly, notification preferences persist under
  `keelson-notification-prefs`.
- **Search**: `src/components/SearchDialog.tsx` covers pages, organizations and
  notifications, from the top bar or `Ctrl+K`/`Meta+K`, with focus trapping and
  restoration. Invoice/customer results were removed: only the loaded API page is
  known locally.
- **Storage safety**: JSON read back from `localStorage` is validated; invalid or
  corrupt values fall back to defaults.

Layout uses CSS logical properties, with a few `[dir='rtl']` rules for directional
icons and the mobile sidebar. Colors come from `src/theme/tokens.css` only
(`#0F766E` primary).

## Source layout

- `src/AppRouter.tsx` — routes: the five public auth paths, the retryable gate, and
  the shell for everything else. `src/components/StatusGate.tsx` renders loading and
  the "cannot reach the API" retry.
- `src/App.tsx` — shell: sidebar navigation, topbar, organization switcher, page
  switch, editor route state, theme control. It reads the session from `useAuth`.
- `src/api/` — the API layer above, with its tests.
- `src/session/` — `session.ts` (payload parsing), `AuthProvider.tsx` (state machine
  and the CSRF hand-off to the client), `AuthContext.ts` (`useAuth`).
- `src/pages.ts` — `PageId`/`NavPageId`.
- `src/screens/auth/` — `SignInScreen`, `SignUpScreen`, `VerifyEmailScreen`,
  `ForgotPasswordScreen`, `ResetPasswordScreen`, plus the shared `AuthLayout`,
  `PasswordField` and `useFieldFocus`.
- `src/screens/` — `Dashboard`, `InvoicesScreen`, `InvoiceEditorScreen`,
  `CustomersScreen`, `TeamScreen`, `BillingScreen`, `PricingScreen`, `Onboarding`,
  `NotificationsScreen`, `AccountScreen`, `SettingsScreen`.
- `src/components/` — shared `StatusBadge`, `PersonCell`, `SearchField`,
  `EmptyState`, `OverlaySurface`, `Icon`, `SearchDialog`, `ProfileMenu`,
  `ThemeControl`, `SessionCard`.
- `src/mocks/` — the remaining mock surfaces: dashboard metrics,
  organization/invitation demo data, billing catalog, notifications.
- `src/search/search.ts`, `src/i18n/`, `src/notifications/`, `src/settings/`,
  `src/theme/`, `src/utils/initials.ts`.

## Tests

`pnpm test` — **61 tests in 6 files**, all in the Node environment:

| File | Covers |
| --- | --- |
| `src/session/session.test.ts` (8) | session parsing, anonymous shape, rejection of an authenticated payload without a usable CSRF token |
| `src/api/auth.test.ts` (8) | one request shape per auth endpoint, sign-in session parsing, `email_not_verified` code, per-field `422` messages, CSRF header on sign-out |
| `src/api/client.test.ts` (19) | JSON success, `204`, credentials/headers, validation error, non-JSON error, network failure, abort passthrough, HTML body, CSRF header on unsafe methods only, one replay after `csrf_invalid` with no retry loop |
| `src/api/customers.test.ts` (7) | list path/limit/cursor, read, create, patch subset, delete `204`, delete `409` |
| `src/api/invoices.test.ts` (9) | status filter, cursor, read, draft create/patch, send/mark-paid, delete, `409`, `422` |
| `src/api/organizations.test.ts` (10) | membership shape filtering, capability helpers, `presentError` mapping, `isConflict` |

There are **no component/DOM tests**: the project has no `jsdom` or Testing
Library dependency, and adding one needs owner approval.

## Not verified here

The auth screens and the authenticated shell were exercised manually in Sprint 3
over `pnpm start` + `php -S`: sign-up, Mailpit verification link, sign-in, customer
and invoice-draft creation (which proves the CSRF header reaches the API), sign-out,
wrong password, forgot/reset, and the sign-in screen in French and Arabic. That run
is evidence, not an automated test: no component/DOM test covers it yet.

## Not implemented

Billing and subscription screens against a real API, the organization-settings
danger zone, the platform-admin console, profile editing, server-side search/sort
(the UI loads up to 100 rows and says when more exist), component/DOM tests, and
real provider sign-in.
