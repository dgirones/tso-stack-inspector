#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0

check_pattern() {
  local label="$1" regex="$2"
  if rg -n --pcre2 "$regex" "$ROOT" -g '*.php' -g '*.js' --glob '!vendor/**' 2>/dev/null; then
    echo "FAIL: $label"
    FAIL=1
  fi
}

check_pattern 'function tso_' '\bfunction tso_[a-z]'
check_pattern 'wp_ajax_tso_ (not tsosi)' 'wp_ajax_tso_[^s]'
check_pattern 'bare tso_ option API' "(get|update|delete)_option\\s*\\(\\s*'tso_[^s]"

if [[ "$FAIL" -eq 0 ]]; then
  echo 'OK: no prefix violations.'
fi
exit "$FAIL"
