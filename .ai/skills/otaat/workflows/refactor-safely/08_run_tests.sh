#!/usr/bin/env bash
# ■ DETERMINISTIC — Test gate after each refactoring step
set -euo pipefail

echo "■ REFACTORING TEST GATE"

if [[ -f ".otat/baseline_status.txt" ]] && [[ "$(cat .otat/baseline_status.txt)" != "PASS" ]]; then
  echo "  ERROR: Baseline was not passing. Cannot validate."
  exit 1
fi

# Detect test runner
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
  echo "■ TEST GATE: PASS ✓ — Safe to continue refactoring"
else
  echo ""
  echo "■ TEST GATE: FAIL ✗ — Last transformation broke tests!"
  echo "  Review the last change and fix before continuing."
  exit 1
fi
