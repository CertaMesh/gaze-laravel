#!/usr/bin/env bash
# ■ DETERMINISTIC — Establish test baseline
set -euo pipefail

echo "■ ESTABLISHING TEST BASELINE"

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
  TEST_CMD="echo 'No test runner detected — manual verification needed'"
fi

echo "  Test command: $TEST_CMD"
echo "  Running..."

if $TEST_CMD 2>&1 | tee .otat/baseline_test_output.txt; then
  echo ""
  echo "■ BASELINE: ALL TESTS PASS ✓"
  echo "PASS" > .otat/baseline_status.txt
else
  echo ""
  echo "■ BASELINE: TESTS FAILING ✗"
  echo "  Cannot proceed with refactoring — tests must pass first."
  echo "FAIL" > .otat/baseline_status.txt
  exit 1
fi
