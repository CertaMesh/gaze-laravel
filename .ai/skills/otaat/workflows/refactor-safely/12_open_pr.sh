#!/usr/bin/env bash
# ■ DETERMINISTIC — Commit, push, and open the PR for a refactor
set -euo pipefail

CONTEXT=".otat/context.md"

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
if [[ -f ".otat/refactor_plan.md" ]]; then
  SUMMARY=$(head -5 ".otat/refactor_plan.md" | grep -v '^#' | grep -v '^$' | head -1 | head -c 60)
fi
if [[ -z "$SUMMARY" ]]; then
  SUMMARY="refactor #${ISSUE_NUM}"
fi

PR_BODY="## Summary\n\n"
if [[ -f ".otat/smells.md" ]]; then
  PR_BODY+="### What was wrong\n\n"
  PR_BODY+=$(grep -v '^#' ".otat/smells.md" | grep -v '^$' | head -5 | tr '\n' ' ')
  PR_BODY+="\n\n"
fi
if [[ -f ".otat/refactor_plan.md" ]]; then
  PR_BODY+="### Transformations\n\n"
  PR_BODY+=$(grep -E '^\d+\.|^-' ".otat/refactor_plan.md" | head -10 | tr '\n' '\n')
  PR_BODY+="\n\n"
fi
PR_BODY+="### Verification\n\n"
PR_BODY+="- Characterization tests written before refactoring (step 02)\n"
PR_BODY+="- Tests run after every transformation (step 08)\n"
PR_BODY+="- Final test suite passes (step 11)\n"
PR_BODY+="- Implementation critiqued and revised (steps 09-10)\n"
PR_BODY+="\n---\n*Generated via OtaaT (One Thing at a Time) workflow*"

git add -A
git commit -m "$(cat <<EOF
refactor: ${SUMMARY} (#${ISSUE_NUM})

OtaaT workflow: characterized behavior, pinned with tests,
refactored in verified steps, self-critiqued.

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
  --title "refactor: ${SUMMARY} (#${ISSUE_NUM})" \
  --body "$(echo -e "$PR_BODY")" \
  --base "$BASE_BRANCH" \
  2>&1)

echo "  ✓ PR opened: $PR_URL"
echo "$PR_URL" > .otat/pr.md
