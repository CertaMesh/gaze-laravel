# Reproduce the Slow Path

Read `.otat/context.md` to understand the reported performance issue.

Create a reproducible benchmark that demonstrates the problem. This means:

1. Identify the exact code path, endpoint, command, or operation that is slow.
2. Set up any required test data, fixtures, or seed state so the benchmark is repeatable.
3. Write a single command (or short script) that exercises the slow path and can be timed.
4. Run it once to confirm it is indeed slow and to get a rough current number.

Write `.otat/benchmark_setup.md` with the following sections:

## What to Measure

Describe the operation being benchmarked (e.g., "loading /api/reports with 10k rows").

## Benchmark Command

Include the exact command in a code block:

```
<the command here>
```

## Metric

What metric matters most: wall-clock time, memory, query count, etc.

## Current (Slow) Number

The rough measurement you just observed.

## Acceptable Target

What would "fixed" look like? Base this on context clues from the issue, comparable operations, or reasonable expectations. Be specific (e.g., "under 200ms" not "faster").
