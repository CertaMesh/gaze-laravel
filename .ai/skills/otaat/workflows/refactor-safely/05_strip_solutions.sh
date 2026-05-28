#!/usr/bin/env bash
# ■ DETERMINISTIC — Strip solution language from smells.md
set -euo pipefail

FILE=".otat/smells.md"
if [[ ! -f "$FILE" ]]; then
  echo "ERROR: $FILE not found."
  exit 1
fi

BEFORE=$(wc -l < "$FILE")

PATTERNS=(
  "we should" "we could" "we need to" "the fix" "the solution"
  "to fix this" "to solve this" "to resolve this" "one approach"
  "should be changed" "should be updated" "should be replaced"
  "should be extracted" "should be moved" "should be renamed"
  "needs to be" "would fix" "can be fixed" "recommend" "suggest"
  "a better approach" "the right way" "instead, " "refactor to"
  "extract into" "move to" "rename to" "replace with"
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
  echo "■ DETERMINISTIC STRIP: Removed $REMOVED lines containing solution language from smells.md"
else
  echo "■ DETERMINISTIC STRIP: No solution language detected (clean)"
fi
