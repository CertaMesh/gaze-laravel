# 03 — Write Compatibility Tests (Agentic)

Read `.otat/usage_audit.md` to understand the full API surface used from the old dependency.

## Instructions

1. Read `.otat/usage_audit.md` for the complete list of API methods and patterns in use.

2. For each API method/pattern used, write a test that exercises the **behavior** — not the implementation. These tests define the contract that the new dependency must satisfy.

   - If the old dep has `.query(sql)`, write a test that runs a query and asserts the result shape.
   - If the old dep has `.render(component)`, write a test that renders and checks output.
   - If the old dep has `.listen(event, handler)`, write a test that fires an event and asserts the handler ran.

3. Place tests in the project's existing test directory, following existing conventions:
   - Use the project's existing test framework (PHPUnit, Jest, Vitest, etc.)
   - Name the test file clearly, e.g., `migration-compatibility.test.ts` or `MigrationCompatibilityTest.php`

4. These tests should pass NOW with the old dependency still installed. Run them to confirm.

5. If any tests fail, fix them — the contract must be green before migration begins.

6. Update `.otat/usage_audit.md` to note which API methods have compatibility test coverage.

## Key Principle

These tests are the **safety net**. Every API method used from the old dependency should have at least one test. When the new dependency is swapped in, these same tests must still pass — proving behavioral equivalence.
