# Step 02 — Write Contract Test

Read `.otat/context.md` and `.otat/acceptance.md`.

Write an integration or contract test that captures the feature's expected behavior. This test is the source of truth — it must fail now and pass once the feature is built.

## What to do

1. **Read the acceptance criteria** carefully. Identify the core behavior that defines this feature.

2. **Write a test** (integration or contract level) that:
   - Tests the primary user-facing behavior described in acceptance.md
   - Covers at least one edge case from the acceptance criteria
   - Uses the project's existing test framework and conventions
   - Is placed in the correct test directory following project patterns

3. **Run the test suite** to confirm the new test fails. If it passes, the test is not testing new behavior — rewrite it.

## Rules

- Only create/modify test files. Do NOT touch any source/implementation files.
- The test should be minimal — test the contract, not implementation details.
- Name the test clearly so it documents what the feature does.
- If the project has no test infrastructure, set it up minimally before writing the test.

## Output

The contract test file(s), committed to the working tree. The test must fail when run.
