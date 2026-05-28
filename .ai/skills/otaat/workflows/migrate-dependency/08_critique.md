# 08 — Critique Migration (Agentic)

Review the entire migration and identify issues before the PR is opened.

## Instructions

1. Run `git diff` against the base branch to see the full migration diff.

2. Read `.otat/usage_audit.md` to confirm every file is marked `[MIGRATED]`.

3. Critique the migration on these dimensions:

   **Missed call sites:**
   - Are there any files that still import/use the old dependency?
   - Run a grep for the old dependency name across the codebase.
   - Check config files, scripts, and CI pipelines.

   **Inconsistent patterns:**
   - Do all migrated files use the new dependency the same way?
   - Are there files where the adapter pattern differs?
   - Are imports consistent (e.g., all using the same import path)?

   **Missing error handling:**
   - Does the new dependency throw different errors or return different shapes?
   - Are there try/catch blocks that reference old error types?
   - Are there edge cases the new dependency handles differently?

   **Test coverage gaps:**
   - Are the compatibility tests comprehensive enough?
   - Are there untested API methods from the audit?

   **Dead code:**
   - Are there helper functions or utilities that only existed for the old dependency?
   - Are there type definitions that are now unused?

4. Write `.otat/critique.md` with:
   - A list of issues found, categorized by severity (blocking, warning, note)
   - Specific file paths and line numbers for each issue
   - Suggested fixes for each issue
