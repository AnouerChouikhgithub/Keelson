# Frontend (React + Vite + TypeScript)

The web app is a **mock-data dashboard shell** for Keelson. It renders a dashboard, an organization switcher, an onboarding flow (create organization, invite teammates) and light/dark/system theming. It does **not** call the backend API, persist data, send email, or enforce permissions; organization switching and invitation actions only change local component state.

Billing, PostgreSQL RLS, real sign-in and the remaining `docs/ui-spec.md` screens are not implemented here.

## Stack (from `package.json` / `pnpm-lock.yaml`)

- React 19.3.1, React DOM 19.3.1
- Vite 8.3.4, `@vitejs/plugin-react` 6.1.2
- TypeScript 6.0.3 — deliberately pinned below 6.1 because `typescript-eslint` 8.71.2 supports `>=4.8.4 <6.1.0` and rejects TypeScript 7 (`typescript-eslint does not support TS 7.0`). Move back to TypeScript 7 only once typescript-eslint ships support.
- ESLint 10.13.0 with `typescript-eslint` 8.71.2, `eslint-plugin-react-hooks`, `eslint-plugin-react-refresh`

## Prerequisites

- Node.js `^20.19.0 || >=22.12.0` (verified on v22.13.0)
- pnpm 12.14.0

## Commands

Run from `frontend/`:

```sh
pnpm install --frozen-lockfile   # install exactly what the lockfile pins
pnpm dev                         # Vite dev server (http://localhost:5173)
pnpm dev --host 127.0.0.1        # same, bound to IPv4 loopback (verified)
pnpm run build                   # tsc -b && vite build → dist/
pnpm run lint                    # eslint .
pnpm preview                     # serve the production build
```

The dev server needs no backend, database or environment variables. `docs`/root notes for the whole project are in [`../how-to-run.md`](../how-to-run.md).

## Source layout

- `src/App.tsx` — the single-page shell: navigation, dashboard, organization switcher, mock onboarding, theme control.
- `src/mocks/dashboard.ts`, `src/mocks/organizations.ts` — typed mock data used by the UI. Add new sample data here rather than inline.
- `src/theme/ThemeProvider.tsx` — theme provider component (light/dark/system, persisted in `localStorage`).
- `src/theme/ThemeContext.ts` — theme context, `ThemeMode` type and `useTheme` hook. Kept separate from the provider so the Fast Refresh lint rule (`react-refresh/only-export-components`) is satisfied.
- `src/theme/tokens.css` — semantic color tokens, including the primary brand color `#0F766E`.

## Verified checks (2026-11-13)

- `pnpm install --frozen-lockfile` — PASS (lockfile up to date)
- `pnpm run build` — PASS (21 modules, exit 0)
- `pnpm run lint` — PASS (exit 0, no findings)
- `pnpm dev --host 127.0.0.1` — PASS: bound `127.0.0.1:5173`, `GET /` returned HTTP 200 (also re-verified on a temporary port). Note that `pnpm run dev -- --host ...` did not forward the flag; put `--host` directly after `dev`.

## Not implemented

- No test runner or test script is configured; adding one requires owner approval first.
- No API client: the project convention is to generate types from the backend OpenAPI spec, which does not exist yet.
- No auth integration, persistence, routing library, Tailwind/shadcn setup, or CI workflow in this package.
