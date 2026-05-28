#!/usr/bin/env bash
# ■ DETERMINISTIC — Run test suite
set -euo pipefail

echo "■ RUNNING TEST SUITE"

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

if $TEST_CMD 2>&1 | tee .otat/test_output.txt; then
  echo ""
  echo "■ TESTS: ALL PASS ✓"
  echo "PASS" > .otat/test_status.txt
else
  echo ""
  echo "■ TESTS: FAILURES FOUND ✗"
  echo "FAIL" > .otat/test_status.txt
fi
