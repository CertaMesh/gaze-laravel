#!/usr/bin/env bash
# ■ DETERMINISTIC — Strip implementation bias from question answers
set -euo pipefail

FILE=".otat/questions.md"

if [[ ! -f "$FILE" ]]; then
  echo "ERROR: $FILE not found."
  exit 1
fi

BEFORE=$(wc -l < "$FILE")

PATTERNS=(
  "we should use"
  "we should go with"
  "the best library"
  "the best approach"
  "the best tool"
  "I recommend"
  "I suggest"
  "clearly the winner"
  "the obvious choice"
  "this means we should"
  "therefore we should"
  "this confirms that"
  "this rules out"
  "this favors"
  "the right choice"
  "the right tool"
  "given this.* we should"
  "based on this.* the best"
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
sed -i.bak 's/\. [Ss]o we should[^.]*\.//g; s/\. [Tt]hus[^.]*should[^.]*\.//g; s/\. [Hh]ence[^.]*should[^.]*\.//g' "$TEMP" && rm -f "$TEMP.bak"
cat -s "$TEMP" > "$FILE"
rm -f "$TEMP"

AFTER=$(wc -l < "$FILE")
REMOVED=$((BEFORE - AFTER))

if [[ $REMOVED -gt 0 ]]; then
  echo "■ DETERMINISTIC STRIP: Removed $REMOVED lines containing implementation bias from questions.md"
else
  echo "■ DETERMINISTIC STRIP: No implementation bias detected (clean)"
fi
