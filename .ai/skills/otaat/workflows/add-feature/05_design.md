# Step 05 — Design the Feature

Read `.otat/acceptance.md` and `.otat/patterns.md`.

Design the feature following the patterns already established in the codebase.

## What to do

1. **Data model changes** — New tables, columns, relationships, migrations. If none needed, say so.

2. **Component/class structure** — New files to create, existing files to modify. Follow the naming and structural patterns from patterns.md.

3. **API surface** — New routes, endpoints, commands, events, or public methods. Include expected inputs and outputs.

4. **State management** — How data flows through the system for this feature. Where state lives, how it updates, what triggers changes.

5. **Error handling** — How the feature fails gracefully. What errors are possible, how they surface to the user.

## Rules

- Follow the patterns documented in `.otat/patterns.md`. Do not invent new patterns unless the existing ones cannot support the feature.
- If you must deviate from existing patterns, explicitly call it out and explain why.
- Do NOT implement anything. No code. Only describe structure and contracts.
- Keep the design minimal — only what is needed to satisfy the acceptance criteria.

## Output

Write the result to `.otat/design.md`.
