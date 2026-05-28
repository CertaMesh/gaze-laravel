#!/usr/bin/env bash
# ■ DETERMINISTIC — Commit, push, and open the PR for a feature
set -euo pipefail

CONTEXT=".otat/context.md"
DECISION=".otat/decision.md"

if [[ ! -f "$CONTEXT" ]]; then
  echo "ERROR: $CONTEXT not found."
  exit 1
fi

ISSUE_NUM=$(grep -oE '#[0-9]+' "$CONTEXT" | head -1 | tr -d '#')
if [[ -z "$ISSUE_NUM" ]]; then
  ISSUE_NUM=$(grep -iE 'issue:?\s*[0-9]+' "$CONTEXT" | grep -oE '[0-9]+' | head -1)
fi
if [[ -z "$ISSUE_NUM" ]]; then
  ISSUE_NUM="???"
fi

BRANCH=$(git branch --show-current)

SUMMARY=""
if [[ -f "$DECISION" ]]; then
  SUMMARY=$(grep -iA1 'recommendation' "$DECISION" | tail -1 | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | head -c 60)
fi
if [[ -z "$SUMMARY" ]]; then
  SUMMARY="add feature #${ISSUE_NUM}"
fi

PR_BODY="## Summary\n\n"
if [[ -f ".otat/acceptance.md" ]]; then
  PR_BODY+="### Acceptance Criteria\n\n"
  PR_BODY+=$(grep -v '^#' ".otat/acceptance.md" | grep -v '^$' | head -5 | tr '\n' ' ')
  PR_BODY+="\n\n"
fi
if [[ -f "$DECISION" ]]; then
  PR_BODY+="### Approach\n\n"
  RECOMMENDATION=$(grep -iA2 'recommendation' "$DECISION" | tail -2 | tr '\n' ' ')
  PR_BODY+="$RECOMMENDATION\n\n"
fi
PR_BODY+="### Verification\n\n"
PR_BODY+="- Contract test written before implementation (step 02)\n"
PR_BODY+="- Test suite passes after implementation (step 11)\n"
PR_BODY+="- Implementation critiqued and revised (steps 12-13)\n"
PR_BODY+="\n---\n*Generated via OtaaT (One Thing at a Time) workflow*"

echo "■ DETERMINISTIC PR CREATION"
echo "  Branch:  $BRANCH"
echo "  Issue:   #$ISSUE_NUM"
echo "  Summary: $SUMMARY"

git add -A
git commit -m "$(cat <<EOF
feat: ${SUMMARY} (#${ISSUE_NUM})

OtaaT workflow: defined acceptance criteria, wrote contract test,
explored patterns, designed, implemented, self-critiqued.

Closes #${ISSUE_NUM}
EOF
)"
echo "  ✓ Committed"

git push -u origin "$BRANCH"
echo "  ✓ Pushed"

BASE_BRANCH="main"
if git rev-parse --verify staging >/dev/null 2>&1; then
  BASE_BRANCH="staging"
fi

PR_URL=$(gh pr create \
  --title "feat: ${SUMMARY} (#${ISSUE_NUM})" \
  --body "$(echo -e "$PR_BODY")" \
  --base "$BASE_BRANCH" \
  2>&1)

echo "  ✓ PR opened: $PR_URL"
echo "$PR_URL" > .otat/pr.md
