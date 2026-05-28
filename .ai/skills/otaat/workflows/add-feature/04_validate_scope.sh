#!/usr/bin/env bash
# ■ DETERMINISTIC — Validate test scope matches feature scope
set -euo pipefail

ACCEPTANCE=".otat/acceptance.md"

if [[ ! -f "$ACCEPTANCE" ]]; then
  echo "ERROR: $ACCEPTANCE not found."
  exit 1
fi

# Check for uncommitted changes outside test files
CHANGED=$(git diff --name-only HEAD 2>/dev/null || true)
NON_TEST=$(echo "$CHANGED" | grep -v -E '(test|spec|Test)' || true)

if [[ -n "$NON_TEST" ]]; then
  echo "■ SCOPE WARNING: Non-test files were modified during contract test step:"
  echo "$NON_TEST"
  echo "  Only test files should change in step 02."
else
  echo "■ SCOPE CHECK: Only test files modified (clean)"
fi

# Count assertions in new test files
NEW_TESTS=$(git diff --name-only HEAD 2>/dev/null | grep -E '(test|spec|Test)' || true)
if [[ -n "$NEW_TESTS" ]]; then
  ASSERTION_COUNT=$(grep -cE '(assert|expect|should|it\(|test\()' $NEW_TESTS 2>/dev/null || echo "0")
  echo "■ Test assertions found: $ASSERTION_COUNT"
  if [[ "$ASSERTION_COUNT" -gt 20 ]]; then
    echo "  ⚠ WARNING: High assertion count ($ASSERTION_COUNT). Review for scope creep."
  fi
else
  echo "■ WARNING: No test files found in diff."
fi
