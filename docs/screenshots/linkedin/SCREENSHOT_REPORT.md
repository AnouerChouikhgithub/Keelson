# Keelson — LinkedIn showcase screenshots

Captured 2026-11-17 from the running application (branch `release/final-stabilization`,
`2e7f10f`), not from mockups.

- Stack: Symfony 7.4 API on the PHP built-in server, PostgreSQL 16 (Docker, host port
  5433), Vite 8.3.4 + React 19 SPA.
- Browser: Chromium driven by the repository's Playwright install (headless).
- Theme: light, English, `en-US` locale, animations disabled at capture time, fonts and
  network settled before each shot, mouse parked outside all controls (no hover state).
- Desktop: 1440 × 1000 CSS px at `deviceScaleFactor: 2` → 2880 × 2000 px PNG.
- Mobile: 390 × 844 CSS px at `deviceScaleFactor: 2`, `isMobile`/`hasTouch` → 780 × 1688 px PNG.

## Data

The screenshots use Keelson's own synthetic fixtures, loaded into a separate local
database (`keelson_showcase_dev`) with the documented loader:

```sh
cd backend
export DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson_showcase_dev?serverVersion=16&charset=utf8"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load --force
```

That seeds five accounts, two organizations, 12 customers, 30 invoices, three
invitations and eight notifications (`backend/src/DataFixtures/`). No records were
invented, edited or inserted for the screenshots, and no customer, production or
personal data is shown: every address is on the reserved `example.test` domain.

The signed-in account is the seeded Owner of **Northwind Demo Co. (fixture)**; the
repository documents that account and its password in `README.md`. The password is
deliberately not repeated here.

## Screenshots

### 01-keelson-overview.png

- Route: `/sign-in` (signed out).
- Demonstrates: product identity (Keelson mark and wordmark), sign-in form, account
  recovery, sign-up entry point and the language selector (English / Français / العربية).
- Viewport: 1440 × 1000 CSS px (2880 × 2000 px).
- Functional: yes — the form posts to `POST /api/auth/login` and this account signs in.
- Limitation / substitution: Keelson has no public marketing or landing page
  (`docs/limitations.md`: no public marketing surface), so the primary application entry
  point — the sign-in screen — is captured instead of a landing page. There is therefore
  no public navigation to show; navigation appears from screenshot 02 onward.

### 02-keelson-dashboard.png

- Route: `/o/{orgId}/dashboard`.
- Demonstrates: authenticated shell (sidebar with workspace switcher, six navigation
  destinations, account row, top bar with search, notifications and theme control),
  four KPIs derived from invoice rows, a 12-month paid-revenue chart, invoices by status
  and the five most recent invoices.
- Viewport: 1440 × 1000 CSS px (2880 × 2000 px).
- Functional: yes — the API answered every figure; the numbers are computed client-side
  from `GET /api/organizations/{id}/invoices`.
- Limitation: "Paid this month" and "Paid rate" are `0`/`0%` because every paid fixture
  invoice is older than the current month. That is the fixture data's real state, not a
  rendering failure; the chart and the paid count in "Invoices by status" show the paid
  history. The recent-invoices table continues below the fold.

### 03-keelson-tenants.png

- Route: `/o/{orgId}/team`.
- Demonstrates: the multi-tenant membership surface — workspace identity in the switcher
  (name, role, subdomain), the member roster with per-member role controls, the
  invite-by-email form, and the two pending invitations with their expiry dates. The
  last-Owner rule is stated under the roster.
- Viewport: 1440 × 1000 CSS px (2880 × 2000 px).
- Functional: yes — roster and invitations are read from the API; role selects and
  invitation actions are live (none were triggered for the capture).
- Limitation / substitution: there is no cross-tenant tenant-directory screen (no
  platform admin panel, no tenant list), by design. The real tenant-administration UI is
  the workspace switcher plus this members/roles/invitations screen, which is what the
  file shows. The pending-invitations table continues below the fold.

### 04-keelson-management.png

- Route: `/o/{orgId}/invoices`.
- Demonstrates: the main data-management screen — search field, status filter tabs
  (All / Draft / Sent / Paid / Overdue), primary create action, and a data table with
  invoice number, customer with initials avatar, issue date, due date, TND amount,
  status badge and row action.
- Viewport: 1440 × 1000 CSS px (2880 × 2000 px).
- Functional: yes — 18 Northwind invoices are listed from the API, filters and search
  are wired to real state, and the rows navigate to invoice detail.
- Limitation: the table is longer than the viewport, so it continues below the fold.

### 05-keelson-configuration.png

- Route: `/o/{orgId}/settings`.
- Demonstrates: configuration and information architecture — appearance control
  (Light / Dark / System), language control (English / Français / العربية with the RTL
  language named in Arabic), the owner-only organization form (name, workspace address
  with its derived `*.keelson.app` hint) and the danger zone.
- Viewport: 1440 × 1000 CSS px (2880 × 2000 px).
- Functional: yes — controls reflect the current state; the organization form and
  delete action are owner-gated by the server as well as the UI.
- Limitation: the danger-zone card continues past the fold (its bottom border is outside the viewport), but its heading, explanation and delete button are fully visible.

### 06-keelson-responsive.png

- Route: `/o/{orgId}/settings`.
- Demonstrates: the same application at phone width — the sidebar collapses to a menu
  button, top-bar actions shrink to icon buttons, the breadcrumb narrows to the page
  name, and cards, segmented controls, language chips and form fields stack and fill the
  width instead of overflowing.
- Viewport: 390 × 844 CSS px (780 × 1688 px), touch emulation.
- Functional: yes — same authenticated session path as the desktop shots.
- Limitation / substitution: Settings is used because it is the one authenticated screen
  whose content stays fully intact at 390 px. At this width the dashboard's KPI values
  fall back to an ellipsis (`TND 11,956.…`) and the invoice, customer and team tables clip
  their right-hand columns. Those screens are real, but they would have shown clipped
  values, so this shot is the honest responsive view.

## Findings from the running application

1. **Fixed: missing field styles on the public auth screens.** `auth.css` renders
   `.field` markup directly, but the rules that style `.field` live in
   `frontend/src/components/Field.css`, which only the `Field` component imported. The
   result was an inline label whose input overlapped it on sign-in, sign-up, forgot and
   reset password. One `@import '../../components/Field.css'` in
   `frontend/src/screens/auth/auth.css` restores the intended layout (the archived
   `.pr-screenshots` sign-in shows the same layout). No markup, behaviour, colour or
   design was changed, and `pnpm run build` passes. The screenshots were captured after
   this fix and therefore show the current implementation.
2. **Pre-existing console warnings (not fixed, out of scope).** Screens that spread the
   `Field` render-prop object onto an input emit React warnings:
   `React does not recognize the 'describedBy' prop` and
   `Received 'false' for a non-boolean attribute 'invalid'` (seen on Settings and Team).
   They are attribute warnings, not runtime errors, and nothing fails to render. No
   request failed (no 4xx/5xx) during the capture.
3. **Responsive truncation.** At 390 px the dashboard KPI values truncate with an
   ellipsis, and the invoice, customer and team tables clip their right-hand columns
   rather than reflowing. Documented above; not changed.
4. **Port 8000 was already occupied** on this machine by an unrelated `symfony.exe`
   welcome page. The API was therefore served on `127.0.0.1:8013` and Vite was pointed at
   it through the supported `KEELSON_BACKEND_ORIGIN` environment variable. No project
   configuration was edited.

## Reproduction

```sh
# 1. Database and mail catcher (127.0.0.1:5433 and Mailpit UI on :8026)
docker compose up -d

# 2. Fixture-only database used for the capture (see "Data" above), then the API
cd backend
export DATABASE_URL="postgresql://app:app@127.0.0.1:5433/keelson_showcase_dev?serverVersion=16&charset=utf8"
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:fixtures:load --force
php -S 127.0.0.1:8013 -t public public/index.php

# 3. SPA pointed at that API (defaults to http://127.0.0.1:8000)
cd ../frontend
KEELSON_BACKEND_ORIGIN=http://127.0.0.1:8013 pnpm start   # http://127.0.0.1:5173
```

The screenshots were taken by a temporary Playwright script (now removed) that signed in
as the seeded Owner, waited for `networkidle`, the visible content and
`document.fonts.ready`, parked the pointer, and captured the viewport only.

## Verification checklist

- Every PNG was opened and inspected: readable, consistent light theme, teal `#0F766E`
  primary, no loading skeletons, no spinners, no browser developer tools, no hover state,
  no broken layout on the captured screens.
- No credentials, tokens, private environment values or personal information appear:
  names are synthetic fixture names, emails are `example.test`, phone numbers are
  `+216000000xx`, organizations are marked `(fixture)`.
- No data, page, chart or capability was fabricated; all six images come from pages that
  the application serves and that the running API backed.
- `git status` after the work: `M frontend/src/screens/auth/auth.css` and the untracked
  `docs/screenshots/` directory only. No commit, no push, no configuration change.
- The temporary showcase database was created for clean fixture data and is kept for
  reproducibility; it can be removed with
  `DROP DATABASE keelson_showcase_dev;` on the local Compose server. The development
  database `keelson_dev` and its persistent volume were not modified or reset.

## Not claimed

These screenshots show a working multi-tenant SaaS starter. They are not a statement
that Keelson is production-ready: PostgreSQL row-level security, billing, MFA and
email-change are still unimplemented (`docs/limitations.md`). Coverage was not measured
locally (no `pcov`/`xdebug`), and the browser journeys were not re-run for this capture.
