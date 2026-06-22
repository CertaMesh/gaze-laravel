# gaze-laravel documentation

`gaze-laravel` is the PHP gate of [`gaze`](https://github.com/CertaMesh/gaze): it exposes
upstream pseudonymization, audit, proxy, and safety-net capabilities through idiomatic Laravel
surfaces so that PII / PHI / secret redaction for LLM and agent pipelines is one
`Gaze::clean()` / `Gaze::restore()` round-trip away.

These docs follow the [Diátaxis](https://diataxis.fr/) framework — pages are grouped by what
you are trying to do, not by feature. Pick the quadrant that matches your intent:

## Tutorials — learning-oriented

Start here on your first run. See [tutorials/](./tutorials/index.md).

- [Getting Started with gaze-laravel](./tutorials/getting-started.md) — install, configure, and make your first `Gaze::clean()` / `Gaze::restore()` call.

## How-to guides — task-oriented

Recipes for a specific job once you know the basics. See [how-to/](./how-to/index.md).

- [Conversational-loop guidance](./how-to/conversational-loops.md) — repeatable token handling across chat / tool-calling / planner turns.
- [Livewire integration](./how-to/livewire-integration.md) — keep public component state token-clean.
- [Queue & Retry guide](./how-to/queue-integration.md) — queue jobs and `GazeRetryPolicy`.
- [Testing guide](./how-to/testing.md) — the fake API and assertion helpers.
- [Upgrading](./how-to/upgrading.md) — per-minor adapter upgrade notes.
- [Proxy](./how-to/proxy-daemon.md) — the `gaze-proxy` daemon and its Artisan commands.
- [Daemon](./how-to/daemon.md) — `gaze daemon` JSONL protocol and lifecycle commands.
- [SafetyNet](./how-to/safety-net.md) — OpenAI-filter and other safety-net backends.
- [Operations](./how-to/operations.md) — health checks, telemetry exclusions, failed-job pruning.
- [Retry discipline](./how-to/retry.md) — retry policy and backoff.
- [Audit, query & export](./how-to/audit-query-export.md) — audit metadata, purge workflow, query/export verbs.

## Reference — information-oriented

Dry facts, tables, and contracts to look up. See [reference/](./reference/index.md).

- [Configuration reference](./reference/configuration.md) — all config / env keys and defaults.
- [Exception reference](./reference/exceptions.md) — typed exceptions and exit-bucket taxonomy.
- [Upstream coverage](./reference/upstream-coverage.md) — the upstream-flag ↔ Laravel-surface parity matrix.
- [Diagnostics](./reference/diagnostics.md) — latency baseline and diagnostic surface.

## Explanation — understanding-oriented

Background and the "why" behind the design. See [explanation/](./explanation/index.md).

- [Architecture](./explanation/architecture.md) — how the package wraps the upstream CLI contract.
- [Blob lifecycle](./explanation/blob-lifecycle.md) — treating session blobs as sensitive, request-scoped values.
- [Enabling NER](./explanation/ner.md) — named-entity recognition alongside regex and rulepack detection.
- [Security model](./explanation/security.md) — adapter guarantees vs. application / infrastructure responsibilities.

## Project compass

[`NORTH_STAR.md`](./NORTH_STAR.md) is the project's decision compass — the audience, the
surface-promotion rule, and the tradeoffs the package makes. Read it to understand *why* a
given upstream feature does or does not get a Laravel surface.
