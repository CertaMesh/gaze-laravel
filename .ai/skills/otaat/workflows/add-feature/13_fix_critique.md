# Step 13 — Address Critique

Read `.otat/critique.md` and the implementation.

Fix every "must fix" item. For deliberately skipped items, document why.

## What to do

1. **Read critique.md** item by item.

2. **For each "must fix" item:**
   - Fix it in the code.
   - If the fix changes behavior, update or add tests.

3. **For each "nice to have" item:**
   - Fix it if the fix is trivial (< 5 lines changed).
   - Otherwise, note in critique.md why it was skipped (e.g., "Deferred — requires refactoring X which is out of scope").

4. **Run the full test suite** after all fixes. Everything must pass.

## Rules

- Do not introduce new features or refactors while fixing critique items.
- If a critique item is wrong (the code is actually correct), note why in critique.md rather than making unnecessary changes.
- Keep fixes minimal and targeted.

## Output

Updated implementation with all "must fix" items addressed. Test suite passing. critique.md updated with resolution notes for skipped items.
