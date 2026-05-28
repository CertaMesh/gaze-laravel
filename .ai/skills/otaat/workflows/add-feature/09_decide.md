# Step 09 — Make the Decision

Read `.otat/design.md` and `.otat/questions.md` (with answers).

Decide on the implementation approach.

## What to do

Write `.otat/decision.md` with three sections:

1. **MVP approach** — The minimum implementation that satisfies every acceptance criterion in `.otat/acceptance.md`. Cut everything that isn't required. Describe what gets built and what gets deferred.

2. **Ideal approach** — If time and complexity were not constraints, what would the best version look like? Include extensibility, performance, and UX polish.

3. **Recommendation** — Pick one (MVP or ideal, or a specific middle ground). One sentence explaining why. This is the approach that will be implemented.

## Rules

- The recommendation must satisfy ALL acceptance criteria. If the MVP doesn't, it's not a valid MVP.
- Base the decision on the empirical answers from questions.md, not on assumptions.
- Be specific enough that a developer could start implementing from this decision alone.
- Do NOT start implementing. Only decide.

## Output

Write the result to `.otat/decision.md`.
