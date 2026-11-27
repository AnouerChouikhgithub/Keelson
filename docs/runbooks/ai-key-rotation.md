# Rotating the AI provider key

Use this when a provider key may have leaked: it was committed, printed by a
diagnostic command, pasted into a ticket, or shared in a screen recording. Revoking
first is the whole point — installing a replacement while the old key is still valid
leaves the exposure open.

The key is read from `AI_API_KEY` by `config/services.yaml`
(`%env(default:app.ai.empty:AI_API_KEY)%`) and reaches the provider only through
`App\Ai\AiGateway`. It never goes to the browser.

## 1. Revoke the old key

1. Open the provider's console (for Groq: **API Keys**) and **delete** the
   compromised key. Creating the replacement first is fine; deleting is the step that
   ends the exposure.
2. Confirm the old key is dead before continuing: a request made with it must fail
   with `401` from the provider.

## 2. Create and store the replacement

Create one new key and copy it once, into the ignored local file:

```sh
# backend/.env.local  (git-ignored: .gitignore ignores backend/.env.*)
AI_API_KEY=<new key>
AI_ENABLED=true
AI_PROVIDER=openai
AI_MODEL=openai/gpt-oss-20b
AI_BASE_URL=https://api.groq.com/openai/v1
```

Rules that keep it there:

- **Never** in `backend/.env` (tracked), `backend/.env.example`, `docs/`, the
  frontend, or a test fixture. Tracked files carry `AI_API_KEY=` with an empty value.
- In CI, the value comes from the repository secret `AI_API_KEY` that
  `.github/workflows/ai-eval.yml` reads; no workflow may echo it.
- In a deployment, put it in the platform secret store, not in the image or the
  compose file.
- Do not pass it on a command line: shell history and process lists keep it.

## 3. Restart and verify

The value is read at boot, so restart the API process (and any worker) after the
change. Then:

```sh
curl -s http://127.0.0.1:8000/api/ai/status          # 200, "enabled": true
php bin/console app:ai:usage --days=1               # a new request appears after one
```

Ask the guide a real question through the UI or
`POST /api/ai/guide`; a `200` with sources proves the replacement works, a `503
ai_unavailable` means the key or the base URL is wrong.

## 4. Confirm nothing tracked holds a secret

```sh
git grep -nE 'gsk_[A-Za-z0-9]{20,}|AI_API_KEY=.+' HEAD   # must print nothing
git status --porcelain                                    # must not list an env file
```

## 5. About Git history and local checkpoints

A key that entered a commit stays in that Git object even after the line is deleted,
and deleting a stash or checkpoint ref does not remove it from the object database.
Rotation in step 1 is what makes those copies worthless; rewriting history to chase
them is not required and must not be done casually, because it changes every commit
id and every clone.

This repository's `main` never contained a provider key: the copies found in
November 2026 were in local agent checkpoint refs (`refs/agents/…`) and in the
ignored `backend/.env.local`. Deleting those refs is optional cleanup that needs the
repository owner's approval; it does not replace revocation.

## 6. Never use these for key handling

`php bin/console debug:dotenv` prints environment **values** — it is a debugging aid,
not a redacted report. `app:config:check` is the safe configuration report: it names
findings and never prints values. Treat any terminal output, CI log or pasted
screenshot from `debug:dotenv` as a leak and rotate.
