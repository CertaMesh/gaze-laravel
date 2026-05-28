# Step 03 — Explore Existing Patterns

Read `.otat/acceptance.md` and explore the codebase.

Find how similar features are built. Document what you find — do NOT design anything.

## What to do

1. **Search the codebase** for features similar in nature to the one described in acceptance.md. Look for:
   - Similar data flows (CRUD, event-driven, request/response)
   - Similar UI patterns (forms, lists, modals, notifications)
   - Similar domain concepts (if adding a "tag" system, find existing "category" or "label" implementations)

2. **Document patterns** you find. For each pattern, note:
   - Which files follow this pattern (exact paths)
   - Naming conventions (file names, class names, function names, variable names)
   - Data flow (where data enters, how it transforms, where it exits)
   - Any abstractions, base classes, traits, or interfaces that new features extend
   - Configuration or registration steps (routes, service providers, middleware, etc.)

3. **Note deviations** — if parts of the codebase are inconsistent, document both patterns and flag the inconsistency.

## Rules

- Do NOT design the feature. Only document what already exists.
- Do NOT suggest which pattern to follow. Just list them.
- Be specific — include file paths, line numbers, class names.
- If no similar patterns exist, say so explicitly.

## Output

Write the result to `.otat/patterns.md`.
