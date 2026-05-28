#!/usr/bin/env bash
# ■ DETERMINISTIC — Commit, push, and open the PR
# Uses context.md and decision.md for PR metadata. No LLM involved.
set -euo pipefail

CONTEXT=".otat/context.md"
DECISION=".otat/decision.md"

if [[ ! -f "$CONTEXT" ]]; then
  echo "ERROR: $CONTEXT not found."
  exit 1
fi

# Extract issue number from context (looks for #NNN or issue: NNN patterns)
ISSUE_NUM=$(grep -oE '#[0-9]+' "$CONTEXT" | head -1 | tr -d '#')
if [[ -z "$ISSUE_NUM" ]]; then
  ISSUE_NUM=$(grep -iE 'issue:?\s*[0-9]+' "$CONTEXT" | grep -oE '[0-9]+' | head -1)
fi

if [[ -z "$ISSUE_NUM" ]]; then
  echo "WARNING: Could not extract issue number from context.md"
  ISSUE_NUM="???"
fi

# Extract repo URL from context
REPO_URL=$(grep -oE 'https://github\.com/[^ ]+' "$CONTEXT" | head -1 | sed 's/[[:space:]]*$//')

# Get current branch name
BRANCH=$(git branch --show-current)

# Get one-line summary from decision.md recommendation
SUMMARY=""
if [[ -f "$DECISION" ]]; then
  SUMMARY=$(grep -iA1 'recommendation' "$DECISION" | tail -1 | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | head -c 60)
fi
if [[ -z "$SUMMARY" ]]; then
  SUMMARY="fix issue #${ISSUE_NUM}"
fi

# Build PR body from artifacts
PR_BODY="## Summary\n\n"

if [[ -f ".otat/problem.md" ]]; then
  PR_BODY+="### Root Cause\n\n"
  # First non-empty, non-header line from problem.md
  PR_BODY+=$(grep -v '^#' ".otat/problem.md" | grep -v '^$' | head -5 | tr '\n' ' ')
  PR_BODY+="\n\n"
fi

if [[ -f "$DECISION" ]]; then
  PR_BODY+="### Approach\n\n"
  RECOMMENDATION=$(grep -iA2 'recommendation' "$DECISION" | tail -2 | tr '\n' ' ')
  PR_BODY+="$RECOMMENDATION\n\n"
fi

PR_BODY+="### Verification\n\n"
PR_BODY+="- Failing test written before fix (step 02)\n"
PR_BODY+="- Test suite passes after fix (step 11)\n"
PR_BODY+="- Implementation critiqued and revised (steps 12-13)\n"
PR_BODY+="\n---\n*Generated via OtaaT (One Thing at a Time) workflow*"

echo "■ DETERMINISTIC PR CREATION"
echo "  Branch:  $BRANCH"
echo "  Issue:   #$ISSUE_NUM"
echo "  Summary: $SUMMARY"
echo ""

# Stage and commit
git add -A
git commit -m "$(cat <<EOF
fix: ${SUMMARY} (#${ISSUE_NUM})

OtaaT workflow: diagnosed root cause, wrote failing test,
implemented fix, self-critiqued and revised.

Closes #${ISSUE_NUM}
EOF
)"

echo "  ✓ Committed"

# Push
git push -u origin "$BRANCH"
echo "  ✓ Pushed to origin/$BRANCH"

# Detect base branch
BASE_BRANCH="main"
if git rev-parse --verify staging >/dev/null 2>&1; then
  BASE_BRANCH="staging"
fi

# Open PR
PR_URL=$(gh pr create \
  --title "fix: ${SUMMARY} (#${ISSUE_NUM})" \
  --body "$(echo -e "$PR_BODY")" \
  --base "$BASE_BRANCH" \
  2>&1)

echo "  ✓ PR opened: $PR_URL"

# Write PR URL to artifact
echo "$PR_URL" > .otat/pr.md
echo ""
echo "■ Done. PR URL saved to .otat/pr.md"
