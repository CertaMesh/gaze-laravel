# Step 12 — Critique the Implementation

Read the implementation using `git diff` to see all changes.

Critique honestly. Find real problems, not style nitpicks.

## What to do

Review the implementation and write `.otat/critique.md` covering:

1. **Missing tests** — Behaviors or edge cases from acceptance.md that aren't tested.

2. **Naming issues** — Variables, functions, files, or classes that don't follow the project's conventions or are misleading.

3. **Pattern violations** — Places where the implementation deviates from the patterns documented in patterns.md without justification.

4. **Edge cases** — Acceptance criteria edge cases that aren't handled or are handled incorrectly.

5. **Dead code** — Code that was added but isn't reachable or necessary.

6. **Security/performance** — Obvious issues only (SQL injection, N+1 queries, unbounded loops, missing auth checks).

## Rules

- Be specific. Include file paths and line numbers for every issue.
- Distinguish between "must fix" and "nice to have."
- If something looks wrong but you're not sure, say so — don't skip it.
- Do NOT suggest rewrites or alternative approaches. Just identify problems.
- If the implementation is clean, say so. Don't invent problems.

## Output

Write the result to `.otat/critique.md`.
