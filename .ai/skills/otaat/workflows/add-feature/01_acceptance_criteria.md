# Step 01 — Define Acceptance Criteria

Read `.otat/context.md` for the feature request.

Define what "done" looks like. Write `.otat/acceptance.md` with the following sections:

## What to write

1. **User-facing behavior changes** — What the user will see, do, or experience differently once this feature ships. Be concrete: describe interactions, outputs, states.

2. **Edge cases that must be handled** — Enumerate boundary conditions, invalid inputs, concurrent access scenarios, empty states, and permission/auth edge cases relevant to this feature.

3. **What should NOT change** — Explicitly list existing behaviors, APIs, UI elements, or data flows that must remain untouched. This protects against scope creep during implementation.

## Rules

- Write NOTHING about implementation (no tech choices, no file names, no architecture).
- Every criterion must be verifiable — a human or test can confirm it passed or failed.
- Use plain language. Avoid jargon unless it comes directly from the feature request.
- If the feature request is ambiguous, state the ambiguity and pick the most conservative interpretation.

## Output

Write the result to `.otat/acceptance.md`.
