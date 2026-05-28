#!/usr/bin/env bash
# ■ DETERMINISTIC — Commit, push, and open migration PR
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

OLD_DEP=$(grep -iE '(from|old|current|replace):?\s' "$CONTEXT" | head -1 | sed 's/.*:\s*//' | xargs)
NEW_DEP=$(grep -iE '(to|new|replacement):?\s' "$CONTEXT" | head -1 | sed 's/.*:\s*//' | xargs)

BRANCH=$(git branch --show-current)
SUMMARY="migrate ${OLD_DEP:-old-dep} to ${NEW_DEP:-new-dep}"

PR_BODY="## Summary\n\n"
PR_BODY+="Migrated from \`${OLD_DEP}\` to \`${NEW_DEP}\`.\n\n"
if [[ -f ".otat/usage_audit.md" ]]; then
  MIGRATED=$(grep -c '\[MIGRATED\]' ".otat/usage_audit.md" || echo "0")
  PR_BODY+="### Migration Scope\n\n"
  PR_BODY+="$MIGRATED files migrated.\n\n"
fi
PR_BODY+="### Verification\n\n"
PR_BODY+="- Usage audit before migration (step 01-02)\n"
PR_BODY+="- Compatibility tests written (step 03)\n"
PR_BODY+="- Tests run after each call site swap (step 06)\n"
PR_BODY+="- Orphan references checked (step 07)\n"
PR_BODY+="- Implementation critiqued and revised (steps 08-09)\n"
PR_BODY+="\n---\n*Generated via OtaaT (One Thing at a Time) workflow*"

git add -A
git commit -m "$(cat <<EOF
migrate: ${SUMMARY} (#${ISSUE_NUM})

OtaaT workflow: audited usage, wrote compatibility tests,
migrated one call site at a time with test gates.

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
  --title "migrate: ${SUMMARY} (#${ISSUE_NUM})" \
  --body "$(echo -e "$PR_BODY")" \
  --base "$BASE_BRANCH" \
  2>&1)

echo "  ✓ PR opened: $PR_URL"
echo "$PR_URL" > .otat/pr.md
