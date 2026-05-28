[LOOP]
# 05 — Swap One Call Site (Agentic Loop)

Migrate exactly ONE file from the old dependency to the new dependency.

## Instructions

1. Read `.otat/usage_audit.md` to find the next file that has **not** been marked `[MIGRATED]`.

2. If all files are already marked `[MIGRATED]`, output `OTAT_DONE` and stop.

3. For the selected file:
   - Read the file and understand how it uses the old dependency.
   - Replace the old dependency's imports/requires with the new dependency's equivalents.
   - Update all API calls to use the new dependency's API.
   - Preserve the existing behavior — do not refactor logic, only swap the dependency.
   - If the new dependency has a different API shape, add any necessary adapter code in that file.

4. After making the change, update `.otat/usage_audit.md`:
   - Mark the file as `[MIGRATED]` in the table.
   - Note any issues encountered or adapter code added.

5. Do NOT migrate multiple files. ONE file per loop iteration. The test gate (step 06) runs after each swap to catch regressions immediately.

## Key Principle

One file at a time. If a migration breaks tests, you know exactly which file caused it. This is the core of OtaaT — small, verifiable steps.
