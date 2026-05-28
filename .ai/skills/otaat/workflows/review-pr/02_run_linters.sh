#!/usr/bin/env bash
# ■ DETERMINISTIC — Run linters and static analysis
set -euo pipefail

echo "■ RUNNING LINTERS & STATIC ANALYSIS"
PASS=true

# PHP (Laravel)
if [[ -f "artisan" ]]; then
  echo ""
  echo "### PHP Linters:"
  if [[ -f "vendor/bin/pint" ]]; then
    echo "  Pint (code style):"
    vendor/bin/pint --test 2>&1 | tail -5 || PASS=false
  fi
  if [[ -f "vendor/bin/phpstan" ]]; then
    echo "  PHPStan (static analysis):"
    vendor/bin/phpstan analyse 2>&1 | tail -10 || PASS=false
  fi
fi

# TypeScript/JavaScript
if [[ -f "package.json" ]]; then
  echo ""
  echo "### JS/TS Linters:"
  if grep -q '"lint"' package.json; then
    echo "  ESLint:"
    npm run lint 2>&1 | tail -10 || PASS=false
  fi
  if grep -q '"typecheck"' package.json; then
    echo "  TypeScript:"
    npm run typecheck 2>&1 | tail -10 || PASS=false
  elif [[ -f "tsconfig.json" ]]; then
    echo "  TypeScript:"
    npx tsc --noEmit 2>&1 | tail -10 || PASS=false
  fi
fi

# Rust
if [[ -f "Cargo.toml" ]]; then
  echo ""
  echo "### Rust Linters:"
  echo "  Clippy:"
  cargo clippy 2>&1 | tail -10 || PASS=false
fi

echo ""
if [[ "$PASS" = true ]]; then
  echo "■ LINTERS: ALL PASS ✓"
else
  echo "■ LINTERS: ISSUES FOUND ⚠"
  echo "  Review the output above. Issues will be noted in the review."
fi
