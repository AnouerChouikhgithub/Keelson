#!/usr/bin/env bash
# Fails when a tracked file carries something that looks like a live credential.
#
#   bash bin/secret-scan.sh            # every tracked file (what CI runs)
#   bash bin/secret-scan.sh path...    # those paths only (used to test the scan)
#
# It looks for the three shapes this repository could plausibly commit by
# accident: an `APP_SECRET` with a real value, a private key block, and a
# provider token. A placeholder is not a secret — `.env.example` exists to show
# the shape without carrying one, so `change-me`, `<32 random bytes>`,
# `%env(...)%` and a bare variable reference are all accepted.
#
# The scan is a tripwire, not a secret manager: it cannot know whether a value is
# live. It catches the mistake, and `docs/runbooks/secret-rotation.md` is what to
# do when it fires.
#
# Exit status: 0 clean, 1 findings (printed as GitHub ::error annotations).
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1
SELF="bin/secret-scan.sh"
findings=0

report() { # file, line, message
  printf '::error file=%s,line=%s::%s\n' "$1" "$2" "$3"
  findings=$((findings + 1))
}

targets=()
if [ "$#" -gt 0 ]; then
  targets=("$@")
else
  while IFS= read -r path; do
    [ "$path" = "$SELF" ] && continue
    [ -f "$path" ] && targets+=("$path")
  done < <(git ls-files)
fi
if [ "${#targets[@]}" -eq 0 ]; then
  echo 'secret-scan: nothing to scan.'
  exit 0
fi

# A value that is a shape, not a secret.
is_placeholder() {
  local value lower
  value="$(printf '%s' "$1" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//')"
  value="${value#\"}"; value="${value%\"}"
  value="${value#\'}"; value="${value%\'}"
  lower="$(printf '%s' "$value" | tr '[:upper:]' '[:lower:]')"
  case "$lower" in
    ''|'*'|null|none|secret|placeholder|changeme|change-me|change_me|example) return 0 ;;
    # The CI job writes this literal into a throwaway .env.local for the e2e run.
    ci-only-not-a-secret) return 0 ;;
  esac
  case "$value" in
    # A shape, a Symfony env() reference, or an unexpanded shell variable.
    '<'*'>'|'%'*'%'|'${'*'}'|'$'[A-Z_]) return 0 ;;
    *change?me*|*placeholder*|*your-*|*random-string*) return 0 ;;
  esac
  return 1
}

# A documented constant that protects nothing: the test environment's secret
# signs tokens for the disposable database `bin/test-db.sh` creates, and it has
# to be the same on every machine for the fixtures to sign in. It is not a
# credential to any deployment, and it is not a value to copy into one.
is_allowed() {
  [ "$1" = 'backend/.env.test' ] && [ "$2" = 'APP_SECRET' ]
}

# 1. APP_SECRET carrying a value.
while IFS= read -r hit; do
  [ -z "$hit" ] && continue
  file="${hit%%:*}"
  rest="${hit#*:}"
  line="${rest%%:*}"
  value="$(printf '%s' "${rest#*:}" | sed -E 's/^[[:space:]]*(export[[:space:]]+)?APP_SECRET=//')"
  is_allowed "$file" 'APP_SECRET' && continue
  is_placeholder "$value" && continue
  report "$file" "$line" 'APP_SECRET carries a value; a tracked file may only carry a placeholder.'
done < <(grep -HnIE -- '^[[:space:]]*(export[[:space:]]+)?APP_SECRET=' "${targets[@]}" 2>/dev/null)

# 2. A private key block.
while IFS= read -r hit; do
  [ -z "$hit" ] && continue
  file="${hit%%:*}"
  rest="${hit#*:}"
  report "$file" "${rest%%:*}" 'A private key block is committed.'
done < <(grep -HnIE -- '-----BEGIN ([A-Z]+ )?PRIVATE KEY( BLOCK)?-----' "${targets[@]}" 2>/dev/null)

# 3. A provider token shape (AI, cloud, source host, chat, search).
while IFS= read -r hit; do
  [ -z "$hit" ] && continue
  file="${hit%%:*}"
  rest="${hit#*:}"
  report "$file" "${rest%%:*}" 'A provider token shape is committed (sk-/AKIA/ghp_/xox-/AIza/hf_/glpat-).'
done < <(grep -HnIE -- '(sk-proj-[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9]{32,}|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|xox[abprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{35}|hf_[A-Za-z0-9]{30,}|glpat-[A-Za-z0-9_-]{20,})' "${targets[@]}" 2>/dev/null)

if [ "$findings" -gt 0 ]; then
  printf 'secret-scan: %d finding(s). Rotate the credential first, then remove it from the file.\n' "$findings"
  echo 'See docs/runbooks/secret-rotation.md.'
  exit 1
fi
echo "secret-scan: clean (${#targets[@]} files)."
