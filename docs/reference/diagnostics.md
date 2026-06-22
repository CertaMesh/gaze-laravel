# Latency baseline / Diagnostic

This page expands the latency diagnostics guidance from the [README](../../README.md). Use it to establish a cold-start baseline before comparing environments or reporting performance issues.

`Gaze::clean()` currently invokes the upstream `gaze clean` command as a one-shot subprocess for every call. With NER enabled, every invocation loads the NER model from disk before it can return a response. This is the current CLI contract, so repeated calls are not a warm-up run: every `gaze:bench --requests=N` sample pays the full model-load cost.

Use `gaze:bench` to measure your own cold baseline:

```bash
php artisan gaze:bench --requests=10
php artisan gaze:bench --requests=10 --json
```

JSON output includes `bench_schema_version`, `mode: "cold"`, `first_ms`, percentile fields, chronological `samples_ms`, and a small environment fingerprint. For `--requests >= 1000`, samples default to `head` mode (first 100 plus last 100); use `--samples=full` or `--samples=none` when you need a different payload size.

Daemon mode is tracked upstream. Once it ships, this package will gain warm worker-pool support in a follow-up release. Until then, this command is diagnostic only: it establishes a cold-start baseline you can compare across machines, releases, or issue reports.
