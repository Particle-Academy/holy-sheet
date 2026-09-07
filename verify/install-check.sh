#!/usr/bin/env bash
#
# Install this package the way a CONSUMER does, then run verify/published.php
# against that install.
#
# The fidelity that matters, and why each piece is there:
#
#   git archive HEAD   — Packagist serves a zip built from the git tree, so this
#                        sees ONLY COMMITTED FILES and honours .gitattributes
#                        export-ignore. Copying the working directory would hide
#                        the most common packaging bug there is: a file that
#                        exists on your machine and was never committed.
#
#   --no-dev           — a consumer does not get require-dev. `src/` reaching for
#                        a dev-only class is invisible to `composer test`, which
#                        has them all installed.
#
#   symlink: false     — a symlinked path repo would run the code from the repo
#                        again, through the same files the suite already used.
#                        Copying is what makes this an install rather than an
#                        alias.
#
# Exit 0 means a consumer can install this and it works.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG_NAME="$(php -r 'echo json_decode(file_get_contents($argv[1]))->name;' "$REPO_ROOT/composer.json")"
WORK="$(mktemp -d)"

# Composer is not always a binary on PATH. On Windows it is commonly a .bat
# shim, and an interactive shell may only know it through a profile alias that
# a script like this one does not inherit — which surfaces as the thoroughly
# misleading "composer: command not found" on a machine where composer plainly
# works. Resolve it explicitly instead.
COMPOSER_BIN=""
for candidate in composer composer.bat composer.phar; do
  if command -v "$candidate" >/dev/null 2>&1; then
    COMPOSER_BIN="$(command -v "$candidate")"
    break
  fi
done

if [ -z "$COMPOSER_BIN" ]; then
  echo "FAIL: could not find composer (tried composer, composer.bat, composer.phar)." >&2
  echo "      Set COMPOSER_BIN, or put composer on PATH." >&2
  exit 1
fi

composer_run() {
  case "$COMPOSER_BIN" in
    *.phar) php "$COMPOSER_BIN" "$@" ;;
    *)      "$COMPOSER_BIN" "$@" ;;
  esac
}

cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

echo "==> Packaging $PKG_NAME from git (committed files only)"
mkdir -p "$WORK/pkg"
git -C "$REPO_ROOT" archive HEAD | tar -x -C "$WORK/pkg"

# A package that archived to almost nothing would install cleanly and pass every
# check below by having no code to break. Same vacuity trap the checks guard.
FILE_COUNT="$(find "$WORK/pkg" -type f | wc -l | tr -d ' ')"
if [ "$FILE_COUNT" -lt 10 ]; then
  echo "FAIL: git archive produced only $FILE_COUNT files — the archive is wrong, not the package." >&2
  exit 1
fi
echo "    $FILE_COUNT files archived"

if [ ! -f "$WORK/pkg/verify/published.php" ]; then
  echo "FAIL: verify/published.php is NOT in the archive." >&2
  echo "      It is either uncommitted or excluded by .gitattributes export-ignore." >&2
  echo "      A verification script that does not ship cannot verify anything." >&2
  exit 1
fi

echo "==> Installing it into a scratch consumer (no dev dependencies)"
mkdir -p "$WORK/consumer"
cat > "$WORK/consumer/composer.json" <<JSON
{
  "name": "verify/consumer",
  "description": "Throwaway consumer used to prove the published package installs and runs.",
  "repositories": [
    { "type": "path", "url": "../pkg", "options": { "symlink": false } }
  ],
  "require": { "$PKG_NAME": "*" },
  "minimum-stability": "dev",
  "prefer-stable": true
}
JSON

( cd "$WORK/consumer" && composer_run install --no-dev --no-interaction --quiet )

INSTALLED="$WORK/consumer/vendor/$PKG_NAME"
if [ ! -d "$INSTALLED" ]; then
  echo "FAIL: composer reported success but $PKG_NAME is not in vendor/." >&2
  exit 1
fi

echo "==> Running the package's own verification, through the consumer's autoloader"
echo
php "$INSTALLED/verify/published.php"
