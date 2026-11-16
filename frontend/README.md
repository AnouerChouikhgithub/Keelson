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
only the IPv6 loopback. A running backend is needed for anything past the
signed-out screen — see [`../README.md`](../README.md).

## Configuration

| Variable | Where | Meaning |
| --- | --- | --- |
| `VITE_API_BASE_URL` | build/runtime env, optional | Absolute API origin when the API is not same-origin. Empty by default. **Never** a secret: Vite inlines every `VITE_*` value into the public bundle. |
| `KEELSON_BACKEND_ORIGIN` | dev-server env, optional | Proxy target for `/api`, `/login`, `/login_check` and `/logout`. Defaults to `http://127.0.0.1:8000`. |

The dev proxy uses `changeOrigin: false` on purpose: the session cookie must stay
same-origin, and the API's mutation guard compares the request `Origin` with
`getSchemeAndHttpHost()`. Do not add permissive CORS to work around a proxy
misconfiguration.

## API layer (`src/api/`)

| File | Responsibility |
| --- | --- |
| `client.ts` | The only `fetch` call site. Same-origin credentials, `cache: 'no-store'`, JSON in/out, `204` and empty bodies, non-JSON error pages, network failures, abort passthrough, and an HTML success body on an API path mapped to `401`. No retries, no invented CSRF header. |
| `errors.ts` | `ApiError` with `kind: 'http' \| 'network' \| 'parse'`, `status` and a sanitized `serverMessage`. |
| `types.ts` | Types mirroring the verified responses, plus the invoice status enum and the shared organization/team shapes. |
| `organizations.ts` | `listOrganizations`, plus `canWriteInvoiceResources` / `canDeleteInvoiceResources`, which mirror `InvoiceVoter` only to hide actions the server would reject. |
| `customers.ts`, `invoices.ts` | One function per endpoint, building paths and payloads. |
| `useApiResource.ts` | Loads one resource, aborts on unmount or loader change, discards stale responses, derives `loading` instead of synchronizing it in an effect. |
| `presentError.ts` | Maps `ApiError` to localized copy and exposes `isConflict`. |

Session and logout live in `src/session/session.ts`, which parses `/api/session`,
validates the logout target and performs sign-out as a real top-level form POST so
the browser follows the provider's end-session redirect.

### Session and tenant rules

- The shell renders only a gate until `/api/session` answers: loading, signed-out,
  or "session unavailable" with a retry.
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
| `401` (client-mapped) | session ended — the next shell load shows the signed-out screen |
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

- `src/App.tsx` — shell: session gate, sidebar navigation, topbar, organization
  switcher, page switch, editor route state, theme control.
- `src/api/` — the API layer above, with its tests.
- `src/session/session.ts` — session parsing, logout-target validation, form-POST
  sign-out.
- `src/pages.ts` — `PageId`/`NavPageId`.
- `src/screens/` — `Dashboard`, `InvoicesScreen`, `InvoiceEditorScreen`,
  `CustomersScreen`, `TeamScreen`, `BillingScreen`, `PricingScreen`, `Onboarding`,
  `NotificationsScreen`, `AccountScreen`, `SettingsScreen`, `SignedOutScreen`.
- `src/components/` — shared `StatusBadge`, `PersonCell`, `SearchField`,
  `EmptyState`, `OverlaySurface`, `Icon`, `SearchDialog`, `ProfileMenu`,
  `ThemeControl`, `SessionCard`.
- `src/mocks/` — the remaining mock surfaces: dashboard metrics,
  organization/invitation demo data, billing catalog, notifications.
- `src/search/search.ts`, `src/i18n/`, `src/notifications/`, `src/settings/`,
  `src/theme/`, `src/utils/initials.ts`.

## Tests

`pnpm test` — **57 tests in 5 files**, all in the Node environment:

| File | Covers |
| --- | --- |
| `src/session/session.test.ts` (18) | session parsing, logout-target validation, rejection of unsafe targets |
| `src/api/client.test.ts` (13) | JSON success, `204`, credentials/headers, validation error, non-JSON error, network failure, abort passthrough, HTML-success-as-`401` |
| `src/api/customers.test.ts` (7) | list path/limit/cursor, read, create, patch subset, delete `204`, delete `409` |
| `src/api/invoices.test.ts` (9) | status filter, cursor, read, draft create/patch, send/mark-paid, delete, `409`, `422` |
| `src/api/organizations.test.ts` (10) | membership shape filtering, capability helpers, `presentError` mapping, `isConflict` |

There are **no component/DOM tests**: the project has no `jsdom` or Testing
Library dependency, and adding one needs owner approval.

## Not verified here

The authenticated shell, customer CRUD and the invoice lifecycle have never been
exercised in a browser on this checkout, because there are no Logto credentials.
They are covered by the backend HTTP tests, the authorization matrix and these API
tests — not by a real sign-in round trip.

## Not implemented

Billing and subscription screens against a real API, the organization-settings
danger zone, the platform-admin console, profile editing, server-side search/sort
(the UI loads up to 100 rows and says when more exist), component/DOM tests, and
real provider sign-in.
