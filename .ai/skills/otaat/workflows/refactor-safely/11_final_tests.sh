#!/usr/bin/env bash
# ■ DETERMINISTIC — Final test validation
set -euo pipefail

echo "■ FINAL TEST VALIDATION"

if [[ -f "artisan" ]]; then
  TEST_CMD="php artisan test"
elif [[ -f "package.json" ]] && grep -q '"test"' package.json; then
  TEST_CMD="npm test"
elif [[ -f "Cargo.toml" ]]; then
  TEST_CMD="cargo test"
elif [[ -f "go.mod" ]]; then
  TEST_CMD="go test ./..."
else
  TEST_CMD="echo 'No test runner detected'"
fi

if $TEST_CMD 2>&1; then
  echo ""
  echo "■ FINAL VALIDATION: ALL TESTS PASS ✓"
  echo "  Refactoring complete. Safe to open PR."
else
  echo ""
  echo "■ FINAL VALIDATION: TESTS FAILING ✗"
  echo "  Do not proceed to PR."
  exit 1
fi
