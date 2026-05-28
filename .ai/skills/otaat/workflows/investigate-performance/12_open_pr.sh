#!/usr/bin/env bash
# ■ DETERMINISTIC — Commit, push, and open performance PR
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
if [[ -f ".otat/decision.md" ]]; then
  SUMMARY=$(grep -v '^#' ".otat/decision.md" | grep -v '^$' | head -1 | head -c 60)
fi
if [[ -z "$SUMMARY" ]]; then
  SUMMARY="performance improvement #${ISSUE_NUM}"
fi

PR_BODY="## Summary\n\n"
if [[ -f ".otat/diagnosis.md" ]]; then
  PR_BODY+="### Root Cause\n\n"
  PR_BODY+=$(grep -v '^#' ".otat/diagnosis.md" | grep -v '^$' | head -3 | tr '\n' ' ')
  PR_BODY+="\n\n"
fi

if [[ -f ".otat/benchmark_log.txt" ]]; then
  PR_BODY+="### Benchmark Results\n\n"
  PR_BODY+="\`\`\`\n"
  PR_BODY+=$(cat .otat/benchmark_log.txt)
  PR_BODY+="\n\`\`\`\n\n"
fi

PR_BODY+="### Verification\n\n"
PR_BODY+="- Profiled before optimization (step 03)\n"
PR_BODY+="- Each hypothesis benchmarked individually (steps 06-08)\n"
PR_BODY+="- Final benchmark confirms improvement (step 11)\n"
PR_BODY+="\n---\n*Generated via OtaaT (One Thing at a Time) workflow*"

git add -A
git commit -m "$(cat <<EOF
perf: ${SUMMARY} (#${ISSUE_NUM})

OtaaT workflow: profiled, diagnosed bottleneck, tested hypotheses
with benchmarks, implemented winning optimization.

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
  --title "perf: ${SUMMARY} (#${ISSUE_NUM})" \
  --body "$(echo -e "$PR_BODY")" \
  --base "$BASE_BRANCH" \
  2>&1)

echo "  ✓ PR opened: $PR_URL"
echo "$PR_URL" > .otat/pr.md
