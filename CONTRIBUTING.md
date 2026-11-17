# Contributing

## Before you start

Read [AGENTS.md](AGENTS.md) — it holds the architecture rules that reviews enforce
(tenant scoping, CSRF, no secrets in the tree, brand tokens) — and
[docs/limitations.md](docs/limitations.md) so you do not build on a gap.

Ask before adding a dependency or changing the architecture. New runtime
dependencies need a reason in the pull request; dev-only tooling is easier but not
automatic.

## Branches

Work happens on short-lived branches off `main`, named for the work:

```
sprint/9-release        a sprint-sized slice
feat/invoice-reminders  one feature
fix/session-lock        one defect
docs/deployment-notes   documentation only
```

Rebase on `main` before opening a pull request; keep the history linear and each
commit meaningful. Never force-push a branch someone else may have checked out.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/), scoped by the part of
the repository:

```
feat(backend): add invoice reminder endpoint
fix(frontend): keep the drawer open when saving fails
test(e2e): cover the invitation acceptance journey
docs(deployment): document the owner role for migrations
chore(backend): drop the unused fixtures command
```

One concern per commit. A commit that changes behaviour updates or adds its tests in
the same commit; a commit that changes the API updates `docs/openapi.yaml` in the
same commit, because a test fails otherwise.

## Checks to run

Run what your change touches, and the full set before a pull request that changes
behaviour.

```sh
# Backend, from backend/
composer stan                 # PHPStan level 8 + the dead-code detector
composer cs                   # php-cs-fixer dry run; `composer cs:fix` to apply
bash bin/test-db.sh start
php bin/phpunit               # or a narrower path: php bin/phpunit tests/Unit/Service
bash bin/test-db.sh stop

# Frontend, from frontend/
pnpm exec tsc -b
pnpm run lint
pnpm test
pnpm run build
pnpm exec knip
node scripts/unused-i18n.mjs

# Journeys, from e2e/
pnpm test
```

`composer check` runs `stan`, `cs` and `test` in that order. CI adds the coverage
gate (`backend/bin/coverage-check.php`, 80% for `src/Service`, `src/Security` and
`src/Controller`), the bundle budget and the browser journeys.

### Writing tests

- **Backend unit tests** (`tests/Unit`) must not need a database or a kernel. If a
  test needs the ORM, it belongs in `tests/Integration` and uses the disposable
  container.
- **Backend integration tests** use `KernelBrowser` and the real schema. They must
  not depend on data another test wrote: create what you need.
- **Frontend component tests** mock `fetch` only (`src/test/fetchMock.ts`). The
  screen under test runs for real; the network is the seam.
- **Browser journeys** assert through roles and labels, never CSS classes, and wait
  on conditions instead of sleeping. A new user-visible flow gets a journey only if
  it crosses the API boundary — a purely visual change does not.

## Style

- TypeScript strict, no `any` without a comment saying why. ESLint and
  php-cs-fixer are the arbiters; both run in CI.
- Prefer readable code over clever code, and explain non-obvious decisions in a
  short comment next to them.
- All colours live in `frontend/src/theme/tokens.css`; use logical CSS properties
  (`padding-inline`, `inset-inline-start`) so Arabic mirrors.
- Every user-visible string goes through the dictionaries in `frontend/src/i18n`,
  in all three locales. `node scripts/unused-i18n.mjs` must stay clean.
- Never commit a secret. Local values go in `backend/.env.local`, which is
  git-ignored.

## Pull requests

Describe the change, the commands you ran and their results, and anything you did
**not** run. A pull request that says "CI will check it" for something you could
have run locally will be sent back. Include a screenshot or recording for a visual
change.
