#!/usr/bin/env bash
# ■ DETERMINISTIC — Post review to GitHub
set -euo pipefail

CONTEXT=".otat/context.md"
REVIEW=".otat/review.md"

if [[ ! -f "$REVIEW" ]]; then
  echo "ERROR: $REVIEW not found."
  exit 1
fi

PR_NUM=$(grep -oE '(#[0-9]+|https://github\.com/[^ ]+/pull/[0-9]+)' "$CONTEXT" | head -1 | grep -oE '[0-9]+$')

# Determine review action based on content
if grep -qi "must fix" "$REVIEW"; then
  ACTION="REQUEST_CHANGES"
  echo "■ Review contains blocking issues — requesting changes"
elif grep -qi "should fix" "$REVIEW"; then
  ACTION="COMMENT"
  echo "■ Review contains suggestions — posting as comment"
else
  ACTION="APPROVE"
  echo "■ No issues found — approving"
fi

echo ""
echo "  PR: #$PR_NUM"
echo "  Action: $ACTION"
echo ""

# Post the review
gh pr review "$PR_NUM" \
  --body "$(cat "$REVIEW")" \
  $(if [[ "$ACTION" = "APPROVE" ]]; then echo "--approve"; elif [[ "$ACTION" = "REQUEST_CHANGES" ]]; then echo "--request-changes"; else echo "--comment"; fi)

echo "  ✓ Review posted to PR #$PR_NUM"
