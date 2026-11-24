# Rotating the secrets this repository once exposed

**Owner action required.** A credential-shaped value sat in a tracked file in this
repository's history. Nothing in the code can fix that: the value stays in every clone
and every fork until someone rotates it at the provider. This page is the checklist for
doing that, and the record of what was actually exposed.

## What was exposed

`backend/.env.dev` was committed and later deleted. It appears in four commits
(`1669777`, `4d7f764`, `f1c83ce`, `644ab68`) and is gone from `HEAD`. The file's history
was inspected by variable **name** only, never by value, and it contained exactly one
variable:

| Variable | Where it is used | Rotate it? |
| --- | --- | --- |
| `APP_SECRET` | Symfony's `kernel.secret`: framework-level signing, and the value `app:config:check` requires | **Yes** |

No database URL, mail credential or AI key appeared under any other name in that file's
history. Names were listed with:

```sh
for sha in $(git log --all --format=%H -- backend/.env.dev); do
  git show "$sha:backend/.env.dev" 2>/dev/null | sed -n 's/^\([A-Za-z][A-Za-z0-9_]*\)=.*/\1/p'
done | sort -u
```

Do not print the values: that command extracts names only, and so should anything you
paste into an issue or a chat.

**History was not rewritten.** That was deliberate: rewriting removes the value from new
clones but not from existing ones, and it breaks every fork and open pull request. The
rotation is what makes the exposed value worthless.

## What to rotate, in this order

1. **`APP_SECRET`.** Generate 32+ random bytes (`openssl rand -base64 48`). Write it into
   the environment's secret store — never a tracked file, never `.env.example`.
2. **The database role passwords**, if the same value was ever reused there. The owner
   role migrates, the application role serves: rotate both if either could have leaked.
   Rotate the application role first only if you can deploy the new `DATABASE_URL` in the
   same window; otherwise rotate the owner role, then the application role, deploying
   immediately after each.
3. **Mail credentials** (SMTP user/password or provider API key) if the value was reused
   for mail. Verification and password-reset mail stop working until it is updated.
4. **The AI provider key**, if it was ever reused from the same source. Removing the key
   from a settings page is not enough — revoke it at the provider.

Each rotation is: change it at the provider or generator, update the secret store, deploy
(or restart the workers), then verify. Do not delete the old value until the new one is
verified, so a rollback does not need a second rotation.

## What rotating `APP_SECRET` does — measured, not assumed

It does **not** sign anyone out. Sessions in this application are rows in the `sessions`
table addressed by an opaque cookie, and nothing in them is derived from `APP_SECRET`, so
an existing cookie keeps working across a rotation. This was measured against a
disposable database and two servers that differed only in `APP_SECRET`:

```text
login with APP_SECRET=aaaa…            -> {"authenticated":true,…}
same cookie, same server              -> {"authenticated":true,…}
same cookie, server with APP_SECRET=bbbb…  -> {"authenticated":true,…}
```

The CSRF token also lives in the session (`SessionPayloadFactory::CSRF_TOKEN_ID`), so a
page that was already open keeps working too.

Consequences worth knowing:

- Rotation is **not** an incident-response "sign everyone out" button. To end every
  session, delete the session rows as part of the same window:
  `DELETE FROM sessions;` (server-side sessions make this exact and immediate — every
  cookie is then unknown and the next request is `401`).
- The value still matters: it is `kernel.secret`, it is what `app:config:check` refuses
  when it is short, missing or a known development value, and anything that signs with it
  must be reissued rather than trusted.
- `docs/deployment.md` and `docs/security.md` used to claim a rotation invalidates every
  session. That was wrong and both now say what is measured above.

## Verification after rotating

```sh
cd backend
php bin/console app:config:check            # APP_SECRET present, long enough, not a placeholder
curl -s https://app.example.com/readyz      # database, RLS and migrations report ok
```

Then, in a browser: sign in, open a list, create and delete one draft invoice (a
CSRF-protected write), and sign out. Finally:

```sh
bash bin/secret-scan.sh                     # "secret-scan: clean"
git log --all -p -- backend/.env.dev | grep -c '^'   # only to confirm the file is gone from HEAD
```

`bin/secret-scan.sh` is also a CI step in the `hygiene` job: a tracked file that carries an
`APP_SECRET` value, a private key block or a provider token fails the build.

## Checklist

- [ ] New `APP_SECRET` generated and stored in the secret store for every environment.
- [ ] Deployed, `app:config:check` and `/readyz` clean, one CSRF-protected write tested.
- [ ] Database role passwords rotated (owner, then application), each deployed and verified.
- [ ] Mail credentials rotated; verification and reset mail received.
- [ ] AI key revoked at the provider and reissued, if it was ever reused.
- [ ] Old values revoked, not merely replaced.
- [ ] `bash bin/secret-scan.sh` clean on the current tree.
- [ ] Whoever ran this recorded the date and the environments in the release notes.
