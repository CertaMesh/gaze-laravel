# 09 — Fix Critique Items (Agentic)

Address every issue raised in the migration critique.

## Instructions

1. Read `.otat/critique.md` for the full list of issues.

2. Work through each issue by severity:
   - **Blocking** items first — these must be fixed before the PR.
   - **Warning** items next — these should be fixed if feasible.
   - **Note** items last — fix if quick, otherwise document in the PR.

3. For each issue:
   - Read the referenced file(s).
   - Apply the suggested fix, or a better fix if you see one.
   - Mark the issue as resolved in `.otat/critique.md` by adding `[RESOLVED]` to the line.

4. After all fixes are applied, run the full test suite to confirm nothing is broken.

5. If any tests fail after fixes, diagnose and resolve before proceeding.

6. Update `.otat/critique.md` with a summary at the bottom:
   ```
   ## Resolution Summary
   - N blocking issues resolved
   - N warnings resolved
   - N notes resolved / documented
   - All tests passing: yes/no
   ```
