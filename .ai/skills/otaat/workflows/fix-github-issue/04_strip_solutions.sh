#!/usr/bin/env bash
# ■ DETERMINISTIC — Strip solution language from problem.md
# No LLM involved. Pure text surgery via pattern matching.
set -euo pipefail

FILE=".otat/problem.md"

if [[ ! -f "$FILE" ]]; then
  echo "ERROR: $FILE not found. Step 03 must run first."
  exit 1
fi

BEFORE=$(wc -l < "$FILE")

# Solution-suggesting patterns to remove (case-insensitive, whole lines)
PATTERNS=(
  "we should"
  "we could"
  "we need to"
  "we can"
  "the fix"
  "the solution"
  "to fix this"
  "to solve this"
  "to resolve this"
  "one approach"
  "one way"
  "a possible"
  "a potential"
  "the correct approach"
  "should be changed to"
  "should be updated"
  "should be replaced"
  "needs to be changed"
  "needs to be updated"
  "needs to be fixed"
  "would fix"
  "would solve"
  "would resolve"
  "can be fixed by"
  "fix would be"
  "solution would be"
  "recommend"
  "suggest"
  "instead, "
  "the right way"
  "a better approach"
  "moving forward"
  "going forward"
)

# Build combined grep pattern
GREP_PATTERN=""
for p in "${PATTERNS[@]}"; do
  if [[ -n "$GREP_PATTERN" ]]; then
    GREP_PATTERN="${GREP_PATTERN}|${p}"
  else
    GREP_PATTERN="$p"
  fi
done

# Remove matching lines, preserving structure
TEMP=$(mktemp)
grep -viE "$GREP_PATTERN" "$FILE" > "$TEMP" || true

# Remove lines that are just "##" or "###" followed by nothing (orphaned headers)
sed -i.bak '/^#{1,6}[[:space:]]*$/d' "$TEMP" && rm -f "$TEMP.bak"

# Remove consecutive blank lines (collapse to single)
cat -s "$TEMP" > "$FILE"
rm -f "$TEMP"

AFTER=$(wc -l < "$FILE")
REMOVED=$((BEFORE - AFTER))

if [[ $REMOVED -gt 0 ]]; then
  echo "■ DETERMINISTIC STRIP: Removed $REMOVED lines containing solution language from problem.md"
  echo "  Lines before: $BEFORE → after: $AFTER"
else
  echo "■ DETERMINISTIC STRIP: No solution language detected in problem.md (clean)"
fi

# Validation: warn if common solution words still present
REMAINING=$(grep -ciE "fix|solve|resolve|change|update|replace|instead|should|recommend" "$FILE" || true)
if [[ $REMAINING -gt 3 ]]; then
  echo "  ⚠ WARNING: $REMAINING lines still contain potential solution language."
  echo "  Review .otat/problem.md manually — some may be legitimate diagnosis wording."
fi
