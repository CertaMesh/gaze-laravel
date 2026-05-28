#!/usr/bin/env bash
# ■ DETERMINISTIC — Remove old dependency and check for orphans
set -euo pipefail

CONTEXT=".otat/context.md"
OLD_DEP=$(grep -iE '(from|old|current|replace):?\s' "$CONTEXT" | head -1 | sed 's/.*:\s*//' | xargs)

echo "■ CLEANUP: Checking for remaining references to $OLD_DEP"

REMAINING=$(grep -rn --include='*.php' --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' --include='*.rs' --include='*.swift' --include='*.go' \
  "$OLD_DEP" . 2>/dev/null | grep -v node_modules | grep -v vendor | grep -v target | grep -v '.otat/' || true)

if [[ -n "$REMAINING" ]]; then
  echo "  ⚠ WARNING: Remaining references found:"
  echo "$REMAINING"
  echo ""
  echo "  These may need manual migration or are in comments/docs."
else
  echo "  ✓ No remaining code references to $OLD_DEP"
fi

# Check package manager files
echo ""
echo "### Package manager status:"
for f in package.json composer.json Cargo.toml go.mod Gemfile requirements.txt; do
  if [[ -f "$f" ]] && grep -q "$OLD_DEP" "$f"; then
    echo "  ⚠ $f still references $OLD_DEP — remove it"
  fi
done

echo ""
echo "■ Cleanup check complete. Remove old dep from package manager if not already done."
