#!/usr/bin/env bash
# ■ DETERMINISTIC — Strip optimization suggestions from diagnosis
set -euo pipefail

FILE=".otat/diagnosis.md"
if [[ ! -f "$FILE" ]]; then
  echo "ERROR: $FILE not found."
  exit 1
fi

BEFORE=$(wc -l < "$FILE")

PATTERNS=(
  "we should" "we could" "we need to" "the fix" "the solution"
  "to optimize" "to speed up" "to improve" "one approach"
  "should be cached" "should be batched" "should be lazy"
  "needs to be" "would fix" "can be optimized" "recommend"
  "suggest" "a better approach" "the right way" "instead, "
  "add an index" "use caching" "batch the" "eager load"
  "prefetch" "memoize" "debounce" "throttle"
)

GREP_PATTERN=""
for p in "${PATTERNS[@]}"; do
  if [[ -n "$GREP_PATTERN" ]]; then
    GREP_PATTERN="${GREP_PATTERN}|${p}"
  else
    GREP_PATTERN="$p"
  fi
done

TEMP=$(mktemp)
grep -viE "$GREP_PATTERN" "$FILE" > "$TEMP" || true
cat -s "$TEMP" > "$FILE"
rm -f "$TEMP"

AFTER=$(wc -l < "$FILE")
REMOVED=$((BEFORE - AFTER))

if [[ $REMOVED -gt 0 ]]; then
  echo "■ DETERMINISTIC STRIP: Removed $REMOVED lines containing optimization suggestions"
else
  echo "■ DETERMINISTIC STRIP: No optimization suggestions detected (clean)"
fi
