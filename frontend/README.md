# Frontend (React + Vite + TypeScript)

The web app is the Keelson dashboard shell. Since the Sprint 2 integration it is connected to the
Symfony API for everything that has a backend:

- the **session** (`GET /api/session`), including the signed-out and unavailable states;
- the caller's **organizations and role** (`GET /api/me/organizations`);
- **customers** — list, read, create, update, delete, including the `409` raised when invoices
  reference a customer;
- **invoices** — list with the server status filter, read, create draft, update draft, send,
  mark paid, delete draft, with `409` conflict handling and server-returned totals.

Still mock, clearly labelled, and never mixed into an API-backed list: dashboard metrics,
onboarding and invitations, team, billing, pricing and notifications. Nothing on those screens is
sent to a server except local preferences and notification read state.

The verified request/response contract is [`../docs/api-contract.md`](../docs/api-contract.md).

## Stack (from `package.json` / `pnpm-lock.yaml`)

- React 19.3.1, React DOM 19.3.1
- Vite 8.3.4, `@vitejs/plugin-react` 6.1.2
- TypeScript 6.0.3 — deliberately pinned below 6.1 because `typescript-eslint` 8.71.2 supports
  `>=4.8.4 <6.1.0` and rejects TypeScript 7. Move back to TypeScript 7 only once typescript-eslint
  ships support.
- ESLint 10.13.0 with `typescript-eslint` 8.71.2, `eslint-plugin-react-hooks`,
  `eslint-plugin-react-refresh`
- Vitest 5.1.0 (Node environment — no `jsdom`, no Testing Library)

No routing, i18n or UI component library is installed; navigation, translations and styles are local
code. The API layer is hand-written because the backend has no OpenAPI document yet.

## Prerequisites

- Node.js `^20.19.0 || >=22.12.0` (verified on v22.13.0)
- pnpm 12.14.0
- A running backend for anything past the signed-out screen (see `../how-to-run.md`)

## Commands

Run from `frontend/`:

```sh
pnpm install --frozen-lockfile   # install exactly what the lockfile pins
npm start                        # Vite on 127.0.0.1:5173
pnpm dev                         # Vite dev server (http://localhost:5173)
pnpm run build                   # tsc -b && vite build → dist/
pnpm run lint                    # eslint .
pnpm test                        # vitest run (56 tests, Node environment)
pnpm preview                     # serve the production build
```

`npm start` is a script alias for `vite --host 127.0.0.1`.

## Configuration

| Variable | Where | Meaning |
| --- | --- | --- |
| `VITE_API_BASE_URL` | build/runtime env, optional | Absolute API origin for a deployment where the API is not same-origin. Empty by default. **Never** put a secret here: Vite inlines every `VITE_*` value into the public bundle. |
| `KEELSON_BACKEND_ORIGIN` | dev-server env, optional | Proxy target for `/api`, `/login`, `/login_check` and `/logout`. Defaults to `http://127.0.0.1:8000`. |

The dev proxy uses `changeOrigin: false` on purpose: the Symfony session cookie must stay
same-origin, and the API's mutation guard compares the request `Origin` with
`getSchemeAndHttpHost()`. Do not add permissive CORS to work around a proxy misconfiguration.

## API architecture (`src/api/`)

| File | Responsibility |
| --- | --- |
| `client.ts` | The only `fetch` call site. Same-origin credentials, `cache: 'no-store'`, JSON in/out, `204` and empty bodies, non-JSON error pages, network failures, abort passthrough, and an HTML success body on an API path mapped to `401` (an expired session is redirected to the provider's sign-in page, which `fetch` follows). No retries, no CSRF header invention. |
| `errors.ts` | `ApiError` with `kind: 'http' \| 'network' \| 'parse'`, `status` and a sanitized `serverMessage`. |
| `types.ts` | Types mirroring the verified responses (customer, invoice, membership, draft input). |
| `organizations.ts` | `listOrganizations`, plus `canWriteInvoiceResources` / `canDeleteInvoiceResources`, which mirror `InvoiceVoter` only to hide actions that the server would reject. |
| `customers.ts`, `invoices.ts` | One function per endpoint, building paths and payloads; nothing else in the app calls `fetch`. |
| `useApiResource.ts` | Loads one resource, aborts on unmount or loader change, discards stale responses, and derives `loading` instead of synchronizing it in an effect. |
| `presentError.ts` | Maps `ApiError` to localized copy (401/403/404/409/429/5xx, network, unexpected) and exposes `isConflict`. Server `detail` is shown only for validation failures. |

Session and logout live in `src/session/session.ts` (the established place, already covered by
tests) rather than a duplicate `api/session.ts`. It parses `/api/session`, validates the logout
target, and performs sign-out as a real top-level form POST so the browser follows the provider's
end-session redirect.

### Session and tenant rules

- The shell renders nothing but a gate until `/api/session` answers: loading, signed-out, or
  "session unavailable" with a retry.
- The authenticated name/email come from the session; the organization list and role come from
  `/api/me/organizations`. There is no demo identity anywhere in that path.
- The selected organization is a **client-side selection**. It is not authorization: every request
  carries the organization in its path and is re-authorized by the server. Switching organizations
  remounts the organization-scoped screens, so no data from the previous tenant survives, and the
  open draft is cleared.
- A membership the server no longer reports cannot be selected.

### Error behaviour

| Status | Shown as |
| --- | --- |
| `400`/`422` | the server's message at form level (field messages are not exposed per field by the API) |
| `401` (client-mapped) | session ended — the next shell load shows the signed-out screen |
| `403` | permission denied (the mutation origin/CSRF guard) |
| `404` | "not found" — also what a foreign tenant returns |
| `409` | conflict explanation and a refetch, never a silent overwrite or an automatic retry |
| `429` | rate-limited message |
| `5xx` | generic server error; internals are never rendered |
| network | connection message with retry |
| abort | nothing — the superseded request is ignored |

## Localization, account and notifications

- **Languages**: English, French and Arabic, with `lang`/`dir` set on `<html>` and Arabic rendered
  right-to-left. Choice persists in `localStorage` under `keelson-locale`.
- **Dictionaries**: `src/i18n/messages.ts` defines a typed English dictionary; French and Arabic are
  typed as `Messages`, so a missing or renamed key is a TypeScript error. Labels that interpolate
  values are functions with typed parameters.
- **Formatting**: `src/i18n/format.ts` formats dates, numbers, percentages and TND currency with
  `Intl` for the active locale. API amounts arrive as decimal strings and are formatted for display,
  never re-summed as authoritative totals.
- **Account menu**: the profile row's overflow button opens a keyboard-accessible menu (`Escape`,
  arrow keys, `Home`/`End`, outside click) with My account, Notifications and Settings.
- **Account screen**: shows the real session identity, the active organization and the caller's role;
  it is read-only because the backend has no profile write endpoint.
- **Notifications**: All/Unread filters, mark-one/mark-all, empty state, bell badge. Read ids persist
  under `keelson-notifications-read`; the notifications themselves are typed mock data.
- **Settings**: appearance reuses `ThemeProvider` (light/dark/system), language applies instantly,
  notification preferences persist under `keelson-notification-prefs`. Labelled as local.
- **Search**: `src/components/SearchDialog.tsx` is an accessible modal over pages, organizations and
  notifications, opened from the top bar or `Ctrl+K`/`Meta+K`, with arrow keys/Enter/Escape, focus
  trapping and restoration, and localized labels. Invoice/customer results were removed: only the
  loaded API page is known locally, so offering them would have implied a search the backend does not
  have.
- **Storage safety**: JSON read back from `localStorage` is validated; invalid or corrupt values fall
  back to defaults, and storage failures never break the session.

Layout uses CSS logical properties, with a few `[dir='rtl']` rules for directional icons and the
mobile sidebar. Colors come from `src/theme/tokens.css` only (`#0F766E` primary).

## Source layout

- `src/App.tsx` — shell: session gate, sidebar navigation, topbar, real organization switcher, page
  switch, editor route state and theme control.
- `src/api/` — the API layer described above (with its tests).
- `src/session/session.ts` — session probe parsing, logout-target validation, form-POST sign-out.
- `src/pages.ts` — shared `PageId`/`NavPageId` types.
- `src/screens/` — `Dashboard`, `InvoicesScreen`, `InvoiceEditorScreen`, `CustomersScreen`,
  `TeamScreen`, `BillingScreen`, `PricingScreen`, `Onboarding`, `NotificationsScreen`,
  `AccountScreen`, `SettingsScreen`, `SignedOutScreen` and `Placeholder`.
- `src/components/` — shared `StatusBadge`, `PersonCell`, `SearchField`, `EmptyState`,
  `OverlaySurface`, `Icon`, `SearchDialog`, `ProfileMenu`, `ThemeControl`, `SessionCard`.
- `src/mocks/` — the remaining mock surfaces: dashboard metrics, organization/invitation demo data,
  billing catalog, notifications.
- `src/search/search.ts` — local search index and filtering.
- `src/i18n/`, `src/notifications/`, `src/settings/`, `src/theme/`, `src/utils/initials.ts`.

## Tests (2026-11-15)

`pnpm test` — **56 tests, exit 0**:

| File | Covers |
| --- | --- |
| `src/session/session.test.ts` (18) | session parsing, logout-target validation and rejection of unsafe targets |
| `src/api/client.test.ts` (12) | JSON success, 204, credentials/headers, validation error, non-JSON error, network failure, abort passthrough, HTML-success-as-`401`, non-JSON success |
| `src/api/customers.test.ts` (7) | list path/limit/cursor, read, create payload, patch subset, delete `204`, delete `409` |
| `src/api/invoices.test.ts` (9) | status filter, cursor, read, draft create, draft patch, send/mark-paid routes, delete, `409`, `422` |
| `src/api/organizations.test.ts` (10) | membership shape filtering, capability helpers, `presentError` mapping, `isConflict` |

There are **no component/DOM tests**: the project has no `jsdom` or Testing Library dependency, and
adding one needs owner approval. Screens are therefore verified by type-checking, lint, the backend
functional tests and the manual check below.

## Verified checks

- 2026-11-15: `tsc -b` exit 0; `pnpm run lint` exit 0 (0 findings); `pnpm run build` exit 0
  (`dist/assets/index-*.js` 370.64 kB / 108.62 kB gzip); `pnpm test` 56/56 pass.
- 2026-11-15 live: SPA served on `127.0.0.1:5175` through the dev proxy to an API on
  `127.0.0.1:8001`. `GET /api/session` returned `500` (no `backend/.env.local`, so no OIDC client),
  and the shell showed the session-unavailable gate with a retry button and no demo identity
  (`../docs/screenshots/session-unavailable.png`); console showed only the two expected 500s.
- 2026-11-13 (historical, mock-only build): five screens rendered in EN/FR/AR across light/dark/system,
  search, profile menu, Arabic RTL, 430 px viewport, storage persistence and favicon verified manually.

**Not verified in a browser:** the authenticated shell, customer CRUD and the invoice lifecycle,
because this checkout has no Logto credentials. Those flows are covered by
`backend/tests/Integration/InvoiceHttpApiTest.php` (HTTP contract), the authorization matrix, and the
frontend API tests — not by a real sign-in round trip.

## Not implemented here

Billing/subscription screens against a real API, the organization-settings danger zone, the
platform-admin console, profile editing, server-side search/sort for customers and invoices (the UI
loads up to 100 rows and says when more exist), component/DOM tests, and real-provider sign-in.
