#!/usr/bin/env bash
# ■ DETERMINISTIC — Automated dependency usage audit
set -euo pipefail

CONTEXT=".otat/context.md"
if [[ ! -f "$CONTEXT" ]]; then
  echo "ERROR: $CONTEXT not found."
  exit 1
fi

# Extract old dependency name (look for "from:" or "old:" or "migrate from" patterns)
OLD_DEP=$(grep -iE '(from|old|current|replace):?\s' "$CONTEXT" | head -1 | sed 's/.*:\s*//' | xargs)
if [[ -z "$OLD_DEP" ]]; then
  echo "WARNING: Could not auto-detect old dependency name from context.md"
  echo "  Add a line like 'From: package-name' to context.md"
  OLD_DEP="UNKNOWN"
fi

echo "■ AUTOMATED DEPENDENCY AUDIT"
echo "  Scanning for: $OLD_DEP"
echo ""

# Search across common file types
echo "### Import/require/use statements:"
grep -rn --include='*.php' --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.rs' --include='*.swift' --include='*.go' \
  -E "(import|require|use|from)\s.*${OLD_DEP}" . 2>/dev/null | grep -v node_modules | grep -v vendor | grep -v target | head -50 || echo "  (none found)"

echo ""
echo "### Package manager references:"
grep -rn "$OLD_DEP" package.json composer.json Cargo.toml go.mod Gemfile requirements.txt Podfile 2>/dev/null || echo "  (none found)"

echo ""
TOTAL=$(grep -rn --include='*.php' --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.rs' --include='*.swift' --include='*.go' \
  "$OLD_DEP" . 2>/dev/null | grep -v node_modules | grep -v vendor | grep -v target | wc -l || echo "0")
echo "### Total references: $TOTAL"
echo ""
echo "■ Audit saved context. Compare with .otat/usage_audit.md for completeness."
