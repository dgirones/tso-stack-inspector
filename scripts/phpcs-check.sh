#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0

echo '==> PHP syntax (php -l)'
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || { echo "FAIL: $f"; FAIL=1; }
  done < <(find "$ROOT" -name '*.php' ! -path '*/vendor/*' -print0)
else
  echo 'WARN: php not found'
fi

echo '==> Duplicate tsosi_ functions'
DUPS="$(rg -n '^\s*function\s+(tsosi_\w+)\s*\(' "$ROOT" -g '*.php' --glob '!vendor/**' || true)"
if [[ -n "$DUPS" ]]; then
  echo "$DUPS" | awk -F: '{print $3}' | sort | uniq -d | while read -r fn; do
    [[ -n "$fn" ]] && echo "FAIL duplicate: $fn" && FAIL=1
  done
fi

if command -v phpcs >/dev/null 2>&1; then
  echo '==> PHPCS'
  phpcs --standard=WordPress --extensions=php "$ROOT" || FAIL=1
fi

bash "$ROOT/scripts/prefix-audit.sh" || FAIL=1
exit "$FAIL"
