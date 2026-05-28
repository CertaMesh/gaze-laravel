#!/usr/bin/env bash
# ■ DETERMINISTIC — Strip solution hints from question answers
# Answers should contain facts only, not recommendations.
set -euo pipefail

FILE=".otat/questions.md"

if [[ ! -f "$FILE" ]]; then
  echo "ERROR: $FILE not found. Steps 06-07 must run first."
  exit 1
fi

BEFORE=$(wc -l < "$FILE")

# Recommendation patterns that leak solution preference into answers
PATTERNS=(
  "therefore we should"
  "therefore the best"
  "this means we should"
  "this means solution"
  "this confirms that"
  "this rules out"
  "this makes .* the best"
  "this makes .* the right"
  "the best approach"
  "the best solution"
  "the right approach"
  "the right solution"
  "clearly the winner"
  "the obvious choice"
  "I recommend"
  "I suggest"
  "we should go with"
  "we should use"
  "we should pick"
  "we should choose"
  "the answer points to"
  "this favors"
  "this supports solution"
  "based on this.* we should"
  "given this.* the best"
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

# Also strip trailing recommendation sentences from answer paragraphs
# (sentences starting with "So " or "Thus " or "Hence " after factual content)
sed -i.bak 's/\. [Ss]o we should[^.]*\.//g; s/\. [Tt]hus[^.]*should[^.]*\.//g; s/\. [Hh]ence[^.]*should[^.]*\.//g' "$TEMP" && rm -f "$TEMP.bak"

cat -s "$TEMP" > "$FILE"
rm -f "$TEMP"

AFTER=$(wc -l < "$FILE")
REMOVED=$((BEFORE - AFTER))

if [[ $REMOVED -gt 0 ]]; then
  echo "■ DETERMINISTIC STRIP: Removed $REMOVED lines containing recommendation language from questions.md"
else
  echo "■ DETERMINISTIC STRIP: No recommendation language detected in answers (clean)"
fi
