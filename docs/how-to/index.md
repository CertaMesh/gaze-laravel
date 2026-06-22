# How-to guides

How-to guides are **task-oriented**: each one walks you through a specific job, assuming you
already know the basics from the [tutorials](../tutorials/index.md). For dry contracts and
tables, see [reference](../reference/index.md); for the reasoning behind the design, see
[explanation](../explanation/index.md).

- [Conversational-loop guidance](./conversational-loops.md) — repeatable token handling across chat, tool-calling, and planner-executor turns.
- [Livewire integration](./livewire-integration.md) — keep public component state token-clean and `GazeSession` values method-scoped.
- [Queue & Retry guide](./queue-integration.md) — run pseudonymization in queue jobs and configure `GazeRetryPolicy`.
- [Testing guide](./testing.md) — use the fake API and assertion helpers in your test suite.
- [Upgrading](./upgrading.md) — per-minor adapter upgrade guide and binary-pin notes.
- [Proxy](./proxy-daemon.md) — run the `gaze-proxy` daemon and its six Artisan commands.
- [Daemon](./daemon.md) — `gaze daemon` JSONL protocol, eviction, and lifecycle commands.
- [SafetyNet](./safety-net.md) — wire up the OpenAI-filter and other safety-net backends.
- [Operations](./operations.md) — health checks, telemetry exclusions, and failed-job pruning.
- [Retry discipline](./retry.md) — retry policy, backoff, and alerting.
- [Audit, query & export](./audit-query-export.md) — audit metadata, the purge workflow, and query/export verbs.

← Back to [documentation index](../README.md).
