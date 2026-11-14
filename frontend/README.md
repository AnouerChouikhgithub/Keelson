# Frontend (React + Vite + TypeScript)

The web app is a **mock-data dashboard shell** for Keelson. It renders a dashboard, invoices, a two-pane invoice editor with live preview, customers, team, billing, a public pricing page, an organization switcher, an onboarding flow (create organization, invite teammates), an account screen, a notifications inbox and functional settings. It does **not** call the backend API, persist data to a server, send email, or enforce permissions; organization switching, invitations, notification read state and preferences only change local state or `localStorage`.

Billing is a mock screen that follows the recorded Phase 8 policy (Free and Pro only, prices and limits shown as unset configuration, Pro test-only). PostgreSQL RLS, real sign-in, the organization-settings profile/danger zone, the platform-admin console and the sign-in/sign-up/recovery screens are not implemented here. Platform admin is deliberately absent: the Phase 1 contract leaves super-admin authority, impersonation and auditing unresolved.

## Stack (from `package.json` / `pnpm-lock.yaml`)

- React 19.3.1, React DOM 19.3.1
- Vite 8.3.4, `@vitejs/plugin-react` 6.1.2
- TypeScript 6.0.3 — deliberately pinned below 6.1 because `typescript-eslint` 8.71.2 supports `>=4.8.4 <6.1.0` and rejects TypeScript 7 (`typescript-eslint does not support TS 7.0`). Move back to TypeScript 7 only once typescript-eslint ships support.
- ESLint 10.13.0 with `typescript-eslint` 8.71.2, `eslint-plugin-react-hooks`, `eslint-plugin-react-refresh`

No routing, i18n or UI component library is installed; navigation, translations and styles are local code.

## Prerequisites

- Node.js `^20.19.0 || >=22.12.0` (verified on v22.13.0)
- pnpm 12.14.0

## Commands

Run from `frontend/`:

```sh
pnpm install --frozen-lockfile   # install exactly what the lockfile pins
npm start                        # Vite on 127.0.0.1:5173 (verified 2026-11-14)
pnpm dev                         # Vite dev server (http://localhost:5173)
pnpm dev --host 127.0.0.1        # same, bound to IPv4 loopback (verified)
pnpm run build                   # tsc -b && vite build → dist/
pnpm run lint                    # eslint .
pnpm preview                     # serve the production build
```

`npm start` is a script alias for `vite --host 127.0.0.1`; it runs with the already-installed dependencies and does not create a second lockfile. Install with pnpm (the committed `pnpm-lock.yaml`) and start with either command.

The dev server needs no backend, database or environment variables. `docs`/root notes for the whole project are in [`../how-to-run.md`](../how-to-run.md).

## Localization, account and notifications

- **Languages**: English, French and Arabic, with `lang`/`dir` set on `<html>` and Arabic rendered right-to-left. Choice persists in `localStorage` under `keelson-locale`.
- **Dictionaries**: `src/i18n/messages.ts` defines a typed English dictionary; French and Arabic are typed as `Messages`, so a missing or renamed key is a TypeScript error. Labels that interpolate values are functions with typed parameters.
- **Formatting**: `src/i18n/format.ts` formats dates, numbers, percentages and TND currency with `Intl` for the active locale. Mock data stores ISO dates and numeric amounts, never pre-formatted English strings.
- **Account menu**: the profile row's overflow button opens a keyboard-accessible menu (`Escape`, arrow keys, `Home`/`End`, outside click) with My account, Notifications and Settings. It is not a popover `<dialog>`; focus returns to the trigger.
- **Notifications**: All/Unread filters, mark-one and mark-all-as-read, an empty state, and a bell badge reflecting the unread count. Read ids persist under `keelson-notifications-read`; notifications themselves are typed mock data in `src/mocks/notifications.ts`.
- **Settings**: appearance reuses the existing `ThemeProvider` (light/dark/system), language applies instantly, and notification preferences persist under `keelson-notification-prefs`. All three are labelled demo controls; no server is contacted.
- **Account**: a read-only demo profile showing the signed-in owner and the active organization; nothing is editable.
- **Search**: `src/components/SearchDialog.tsx` is an accessible modal search over local mock data only — pages, organizations, invoices, customers and notifications. It opens from the top-bar control or `Ctrl+K`/`Meta+K` (the shortcut is only intercepted to open it), supports arrow keys/Enter/Escape, traps focus, restores focus to the trigger, and shows empty and no-result states. Result labels and kinds are localized. It performs no backend or global search.
- **Storage safety**: JSON values read back from `localStorage` are validated (`keelson-locale`, `keelson-theme`, `keelson-notifications-read`, `keelson-notification-prefs`); invalid or corrupt values fall back to defaults, and storage failures never break the session.
- **Favicon**: `index.html` links `/Keelson.svg`, the project logo, instead of the removed Vite starter icon.

Layout uses CSS logical properties (`inset-inline-*`, `margin-inline-start`, `text-align: start`), with a small number of `[dir='rtl']` rules for directional icons and the mobile sidebar. Colors come from `src/theme/tokens.css` only.

## Source layout

- `src/App.tsx` — single-page shell: sidebar navigation, topbar, organization switcher, page switch and theme control.
- `src/pages.ts` — shared `PageId`/`NavPageId` types.
- `src/screens/` — `Dashboard`, `InvoicesScreen`, `InvoiceEditorScreen`, `CustomersScreen`, `TeamScreen`, `BillingScreen`, `PricingScreen`, `Onboarding`, `NotificationsScreen`, `AccountScreen`, `SettingsScreen` and `Placeholder`.
- `src/components/` — shared `StatusBadge`, `PersonCell`, `SearchField`, `EmptyState`, `OverlaySurface`, `Icon`, `SearchDialog`, `ProfileMenu`, `ThemeControl`; `src/hooks/useEscapeKey.ts` closes transient surfaces on Escape.
- `src/mocks/invoices.ts`, `src/mocks/customers.ts`, `src/mocks/billing.ts` — invoice table rows, customer records with TND totals, and the Free/Pro catalog with deliberately unset limits.
- `src/components/Icon.tsx`, `src/components/ProfileMenu.tsx`, `src/components/SearchDialog.tsx` — shared icon set, account menu and search dialog.
- `src/search/search.ts` — local search index (pages, organizations, invoices, customers, notifications), filtering and platform shortcut label.
- `src/i18n/` — dictionaries, `Intl` formatters, `I18nProvider` and `useI18n`.
- `src/mocks/dashboard.ts`, `src/mocks/organizations.ts`, `src/mocks/notifications.ts` — typed, locale-neutral mock data. Add sample data here rather than inline.
- `src/notifications/useNotifications.ts`, `src/settings/usePreferences.ts` — `localStorage`-backed read state and preferences.
- `src/theme/ThemeProvider.tsx`, `src/theme/ThemeContext.ts`, `src/theme/tokens.css` — theme provider (persisted as `keelson-theme`), context/hook and semantic tokens, including the primary brand color `#0F766E`.

## Verified checks (2026-11-13)

- `pnpm install --frozen-lockfile` — PASS (lockfile up to date)
- `pnpm run build` — PASS (`tsc -b && vite build`, 40 modules, exit 0)
- `pnpm run lint` — PASS (exit 0, no findings)
- `git diff --check` — PASS (no whitespace errors)
- Number formatting — PASS: `formatNumber('en', 28.6, 0)` → `29`, `(…, 1)` → `28.6`, and a repeat call with `0` still returns `29` (precision is part of the formatter cache key). French returns `29` and `28,6`.
- Dev-server smoke test — PASS: all five screens (Dashboard, Onboarding, Account, Notifications, Settings) rendered in all three languages for each appearance option (light, dark, system — system resolved to dark on this machine), 45 screen/language/appearance combinations. Search opened via the button and `Ctrl+K`, matched an invoice/customer, navigated with Enter, showed the no-result state, closed with Escape and restored focus; Arabic results used localized kinds. Arabic set `dir="rtl"` with the sidebar on the right; the search dialog fit a 430 px viewport; dates/amounts used locale formats; mark-one/mark-all and preferences persisted after reload. The favicon resolved to `/Keelson.svg`. No console errors or failed requests.
- No automated UI tests exist, so the smoke test is manual only.

## Not implemented

- No test runner or test script is configured; adding one requires owner approval first.
- No API client: the project convention is to generate types from the backend OpenAPI spec, which does not exist yet.
- No auth integration, persistence beyond `localStorage`, routing library, Tailwind/shadcn setup, or CI workflow in this package.
