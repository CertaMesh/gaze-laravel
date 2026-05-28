# 01 — Audit Dependency Usage (Agentic)

Read `.otat/context.md` to identify the **old** and **new** dependency names.

## Instructions

1. Parse `.otat/context.md` for:
   - The old dependency (look for "From:", "Old:", "Current:", or "Replace:" lines)
   - The new dependency (look for "To:", "New:", or "Replacement:" lines)

2. Search the entire codebase for every reference to the old dependency:
   - `import` / `require` / `use` / `from` statements
   - Direct API calls and method invocations
   - Type references and interface usage
   - Configuration files referencing the dependency
   - Test files that use the dependency

3. Skip directories: `node_modules/`, `vendor/`, `target/`, `.otat/`

4. Categorize each usage by pattern, for example:
   - Database queries
   - Event listeners
   - HTTP calls
   - Rendering / UI
   - Configuration / setup
   - Test utilities

5. Write `.otat/usage_audit.md` with the following structure:

```markdown
# Dependency Usage Audit

**Old dependency:** `<name>`
**New dependency:** `<name>`

## Files Using Old Dependency

| File | API Methods Used | Category |
|------|-----------------|----------|
| src/foo.ts | `.query()`, `.connect()` | Database queries |
| ... | ... | ... |

## API Surface Summary

### Category: <name>
- `method1()` — used in N files
- `method2()` — used in N files

### Category: <name>
- ...

## Total: N files, M unique API methods
```

Ensure every file is listed. This audit drives the entire migration — missing a file means it won't get migrated.
