#!/usr/bin/env bash
# ■ DETERMINISTIC — Test gate after dependency swap
set -euo pipefail

echo "■ MIGRATION TEST GATE"

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

if $TEST_CMD 2>&1 | tee .otat/latest_test_output.txt; then
  echo ""
  echo "■ TEST GATE: PASS ✓"
else
  echo ""
  echo "■ TEST GATE: FAIL ✗ — Last migration step broke tests!"
  exit 1
fi
