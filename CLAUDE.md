# CLAUDE.md

See [AGENTS.md](./AGENTS.md) for the shared repo rules and tooling preference.

## Mission

`gaze-laravel` is the **PHP gate of [`gaze`](https://github.com/CertaMesh/gaze)**. When upstream `gaze` ships a feature, this package exposes it via idiomatic Laravel surfaces — Facade methods, artisan commands, config keys — when it makes sense in PHP. Detection logic stays upstream; this package never re-implements pseudonymization in PHP.

Living roadmap: Solo scratchpad `convention/living-roadmap` (1550) → `roadmap/gaze-feature-coverage` (1538). Orchestrators maintain on every release.

See AGENTS.md §Mission for the full operational implications.

<!-- scribe-snippet:commit-discipline:start sha256=6669245ff2b744a822a0834057b4ecdba22c31e336c8a5e6fc474b92fba9a013 -->
## Agent Commit Discipline

Commit after each logical phase of work — not just at the end.

### When to commit
- After implementing a feature/function and its directly related files
- After adding or updating tests for that feature
- After wiring up routes, config, or integration glue
- After fixing lint/type errors or reviewer-requested changes

A "phase" typically touches 1–5 files toward one purpose. Don't commit after every single file edit, and don't batch all work into one commit.

### Commit message format

```
[agent] Imperative description of what and why

Step N of task: "task name"
```

- `[agent]` prefix is mandatory — distinguishes agent work in git log/blame
- Include the step number when the task has a known plan with ordered steps
- Body is optional — use it for non-obvious decisions or trade-offs

### Rules
- NEVER amend a previous commit — always create new commits
- NEVER force-push or rewrite history on agent branches
- Run tests/lint before each commit — if they fail, fix and commit the fix separately
- Stage specific files by name — never use `git add .` or `git add -A`

### On PR merge
- Always squash merge to main — keeps main history clean
- The granular commits exist for review and debugging during development, not for main
<!-- scribe-snippet:commit-discipline:end -->
