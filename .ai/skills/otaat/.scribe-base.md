---
name: otaat
description: OtaaT — One Thing at a Time. Hybrid blueprint runner that mixes deterministic shell scripts (guardrails, automation) with agentic reasoning steps for methodical multi-step work. Use when the user says 'otaat', 'one thing at a time', wants a structured workflow for bug fixes, features, refactors, dependency migrations, PR reviews, or performance investigations, or invokes /otaat. Ships with 6 workflows; extensible with custom ones.
---

# OtaaT — One Thing at a Time

Blueprint-style orchestrator for methodical multi-step work. Each step does **one thing only**. Combines deterministic code nodes with agentic reasoning nodes, following [Stripe's Minions blueprint pattern](https://stripe.dev/blog/minions-stripes-one-shot-end-to-end-coding-agents-part-2) and [Caleb Porzio's OtaaT concept](https://noteson.work). Ships with 6 built-in workflows (bug fixes, features, refactors, dependency migrations, PR reviews, performance investigations); extensible with custom ones.

## Blueprint Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                    BLUEPRINT RUNNER (you)                      │
│                                                                │
│  You are the orchestrator. You do NOT execute agentic steps    │
│  yourself. You dispatch them as subagents and wait for the     │
│  result. You only execute deterministic .sh scripts directly.  │
│                                                                │
│  ┌──────────┐   ┌───────────┐   ┌──────────────────┐         │
│  │ CONTEXT  │──▶│  STEP N   │──▶│  ARTIFACT CHECK  │──┐      │
│  │  SEED    │   │ subagent  │   │  (read artifact)  │  │      │
│  │  (you)   │   │ or bash   │   │  confirm exists   │  │      │
│  └──────────┘   └───────────┘   └──────────────────┘  │      │
│       ▲                                                │      │
│       └────────────── LOOP? ◄──────────────────────────┘      │
│                         │                                      │
│                    next step                                   │
└──────────────────────────────────────────────────────────────┘

Node types:
  ■ Deterministic (.sh) — YOU run via Bash tool. No subagent.
  ☁ Agentic (.md)       — MUST dispatch as subagent via Agent tool.
  ⟳ Loop (.md + [LOOP]) — Dispatch subagent repeatedly until OTAT_DONE.
```

## Why Subagents Are Mandatory

The original OtaaT runs each step in a **fresh Claude session** (`claude --print`). This is the core mechanism, not a nice-to-have:

1. **Context isolation** — a diagnosis subagent cannot see the implementation plan. It can only describe what's broken, not jump to fixing it. Without isolation, the LLM will shortcut every time.
2. **No solution bleeding** — the strip steps (04, 08) exist because LLMs conflate "what's broken" with "how to fix it." If all steps share context, the agent already "knows" its preferred solution and the strip is theater.
3. **Honest critique** — step 12 must critique the implementation. If the same context window wrote the code AND reviews it, the critique will be sycophantic. A fresh subagent with only the diff gives genuine pushback.
4. **Contained blast radius** — a subagent that goes wrong doesn't poison the orchestrator's context. You can re-run just that step.

**Do not rationalize skipping subagents.** "This is simple" is exactly the reasoning the system is designed to prevent. Simple bugs still benefit from isolated diagnosis → implementation → critique.

## Workflows

Workflows live in `~/.claude/skills/otaat/workflows/<name>/`. Each workflow contains numbered step files that are either:
- **Agentic prompts** (`.md`) — dispatched as subagents
- **Deterministic scripts** (`.sh`) — executed directly via Bash

### Available workflows:
- `fix-github-issue` — 14-step workflow for diagnosing and fixing a GitHub issue, then opening a PR
- `add-feature` — 14-step workflow for implementing a new feature with acceptance criteria, contract tests, and design review
- `refactor-safely` — 12-step workflow for safe refactoring with characterization tests, baseline coverage, and incremental changes
- `migrate-dependency` — 10-step workflow for swapping a dependency with usage audit, compatibility tests, and callsite migration
- `review-pr` — 9-step workflow for thorough PR review covering architecture, correctness, and security
- `investigate-performance` — 12-step workflow for diagnosing and fixing performance issues with profiling and benchmarks

## Orchestration Rules

When this skill is invoked:

1. **Ask the user** which workflow to run (default: `fix-github-issue`) and gather required inputs (repo URL, issue number/description)
2. **Create working directory**: `.otat/` in the project root (gitignored)
3. **Seed context**: Write `.otat/context.md` with the user's input
4. **Run steps sequentially** by reading each numbered file from the workflow directory
5. **For each step**:

### Deterministic steps (`.sh` files)
Run directly via the Bash tool. Report pass/fail. If the script fails, stop the workflow.

### Agentic steps (`.md` files)
**MUST** be dispatched as a subagent using the Agent tool:

```
Agent(
  prompt: "<contents of the step .md file>

Working directory: <project root>
OtaaT artifacts directory: .otat/

Read the artifact files listed above for context. Write your output artifact when done.",
  subagent_type: "general-purpose",
  description: "OtaaT step NN: <step name>"
)
```

**Do NOT**:
- Execute agentic steps inline in the main conversation
- Combine multiple steps into one subagent call
- Skip steps because the problem "seems simple"
- Pass information between steps via conversation — only via `.otat/` artifact files

### Loop steps (`.md` files starting with `[LOOP]`)
Dispatch as a subagent. Read the output. If it does NOT contain `OTAT_DONE`, dispatch again (max 10 iterations).

### Between steps
- Announce: `"✓ Step NN complete — <artifact produced>. Next: Step NN+1 — <step name>"`
- Read the artifact to confirm it was written
- If an artifact is missing, re-run the step once before failing

### On failure
Stop, report which step failed, preserve all artifacts for debugging.

## Working Directory Structure

```
.otat/
├── context.md          # Seeded by orchestrator
├── reproduce.md        # Step 01 output
├── problem.md          # Step 03 output (cleaned by step 04)
├── solutions.md        # Step 05 output (accumulated)
├── questions.md        # Step 06 output (answers added by step 07, cleaned by step 08)
├── decision.md         # Step 09 output
├── alleyoop.md         # Step 10 output
├── critique.md         # Step 12 output
└── pr.md               # Step 14 output
```

## Adding New Workflows

Create a directory under `~/.claude/skills/otaat/workflows/<name>/` with numbered files:
- `01_name.md` for agentic steps (dispatched as subagents)
- `04_name.sh` for deterministic steps (run via Bash)
- First line `[LOOP]` in `.md` files enables iteration

## Key Principles

1. **One thing per step** — if you're tempted to also do X, stop. That's the next step.
2. **Subagents are mandatory** — every `.md` step runs in an isolated subagent. No exceptions. No "this is simple enough to do inline."
3. **Deterministic guardrails** — `.sh` steps run fixed code. The LLM has zero creative latitude.
4. **Strip solutions from diagnosis** — steps 04 and 08 exist because LLMs conflate "what's broken" with "how to fix it."
5. **Empirical answers only** — step 07 answers by running code, not by reasoning.
6. **Prep before surgery** — step 10 means the implementer never has to explore.
7. **Self-critique before shipping** — step 12 catches what the implementer was too close to see.

## Adaptation

- **Not a bug?** Skip steps 01-02. Start at 03 with "diagnose the requirement."
- **Simple fix?** After step 03, if the root cause is obvious and there's only one reasonable solution, skip steps 05-08 (solution generation and questions) and go straight to step 09. **Still use subagents for every remaining step.**
- **Feature work?** Replace "reproduce" with "acceptance criteria" and "failing test" with "contract test."
