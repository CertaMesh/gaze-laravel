#!/usr/bin/env bash
# ■ DETERMINISTIC — Fetch PR and capture stats
set -euo pipefail

CONTEXT=".otat/context.md"
if [[ ! -f "$CONTEXT" ]]; then
  echo "ERROR: $CONTEXT not found."
  exit 1
fi

# Extract PR number or URL
PR_REF=$(grep -oE '(#[0-9]+|https://github\.com/[^ ]+/pull/[0-9]+)' "$CONTEXT" | head -1)
if [[ -z "$PR_REF" ]]; then
  echo "ERROR: No PR number or URL found in context.md"
  echo "  Add a line like 'PR: #123' or the full GitHub PR URL"
  exit 1
fi

# If it's a full URL, extract the number
PR_NUM=$(echo "$PR_REF" | grep -oE '[0-9]+$')

echo "■ FETCHING PR #$PR_NUM"

# Checkout the PR
gh pr checkout "$PR_NUM"
echo "  ✓ Checked out PR #$PR_NUM"

# Capture diff stats
echo ""
echo "### Diff Stats:"
gh pr diff "$PR_NUM" --stat | tee .otat/diff_stats.txt
echo ""

# Capture full diff for review
gh pr diff "$PR_NUM" > .otat/pr_diff.txt
echo "  ✓ Full diff saved to .otat/pr_diff.txt"

# Capture PR metadata
gh pr view "$PR_NUM" --json title,body,author,baseRefName,headRefName,files,additions,deletions > .otat/pr_metadata.json
echo "  ✓ PR metadata saved to .otat/pr_metadata.json"

# Summary
ADDITIONS=$(gh pr view "$PR_NUM" --json additions --jq '.additions')
DELETIONS=$(gh pr view "$PR_NUM" --json deletions --jq '.deletions')
FILES=$(gh pr view "$PR_NUM" --json files --jq '.files | length')
echo ""
echo "  Files changed: $FILES"
echo "  Additions: +$ADDITIONS"
echo "  Deletions: -$DELETIONS"
