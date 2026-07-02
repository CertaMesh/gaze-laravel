# Audit Metadata

`gaze` v0.5+ writes a SQLite audit log of redaction events to a single file configured via `gaze.audit_db_path`. The Laravel adapter exposes this surface through `Gaze::audit()`.

## Configuration

Set the path via `GAZE_AUDIT_DB_PATH` or directly in `config/gaze.php`:

```php
// config/gaze.php
'audit_db_path' => env('GAZE_AUDIT_DB_PATH', storage_path('app/gaze/audit.sqlite')),
```

The default in this package is `null`; opt in by setting the env. Once set:

- `Gaze::clean()` automatically forwards `--audit-db=<path>` to the binary, so every redaction round trip writes a row.
- `Gaze::audit()->purge()`, `query()` (with its `export()`), and `safetyNetQuery()` read from the same DB.

A per-call override is supported: `Gaze::audit('/some/other.sqlite')->purge()` wins over the config value. This is useful for tenant-isolated audit DBs or one-off maintenance scripts. Passing `null`, or omitting the argument, falls back to the config value.

The binary creates the SQLite file on first write; do not pre-create it.

## Permissions

If your web (`php-fpm`) and queue (`artisan queue:work`) processes run under different OS users, both must be able to read and write the SQLite file. The binary creates files in mode `0600`; widen via deploy tooling if needed.

## Atomicity, Retries, And The Redaction Trail

When `audit_db_path` is set, `Gaze::clean()` performs two side effects in one binary invocation: the encrypted-blob round trip and a row write to the audit DB. These are not transactional from the adopter's perspective.

Three failure modes to know about:

1. Clean succeeds; audit row is missing. The binary may exit cleanly even if the audit-side write was a no-op. If complete-trail guarantees matter, treat audit rows as advisory and run a reconciliation job that compares clean response counts against audit-row counts on a cadence.

2. SQLite contention triggers a queue retry. `GazePipelineException` is retryable; when the audit DB hits BUSY/LOCKED contention, the queue retries `Gaze::clean()`. Each retry produces a different ciphertext blob because encryption is non-deterministic, and may also write a duplicate audit row for the failed attempt. Adopter code that compares ciphertext blobs across retries will break. Audit rows for failed-then-retried calls may exist or not depending on lock timing.

3. Cross-joining audit rows back to clean response data reverses the redaction. The audit row's `recognizer_id`, `pii_class`, and token slot, such as `<Email_3>` for the third email in the row, are re-identification side channels. **DO NOT cross-join audit rows with `GazeSession::cleanText`** and do not join audit DB rows to clean-side response data in adopter code. The audit boundary is one-way: audit rows are for retention compliance and forensic queries; the clean response is for downstream LLM consumption. Keep them separate.

## Purging Old Rows

```php
use CertaMesh\Gaze\Facades\Gaze;

// Dry-run: count matching rows without deleting.
$preview = Gaze::audit()
    ->purge()
    ->before(now()->subDays(90))
    ->dryRun();

echo $preview->count; // ?int: null if upstream stdout shape is not recognized
echo $preview->rawOutput; // raw stdout for inspection

// Real delete. Schedule via queue or cron.
$result = Gaze::audit()
    ->purge()
    ->before(now()->subDays(90))
    ->execute();
```

`before()` accepts either a `CarbonInterface` or an ISO 8601 string. Carbon values are converted to UTC Zulu (`...Z`) before forwarding.

> Warning: `execute()` deletes rows. Call `dryRun()` first when uncertain. The package does not fall back to dry-run if `execute()` is called by mistake.

`AuditPurgeResult::$count` is nullable. The pinned upstream (0.11.x) prints `{"dry_run":bool,"matched":N,"deleted":N}` (verified against the real binary); `$matched` and `$deleted` carry those fields and `$count` is the operative number — `matched` on dry runs, `deleted` on real runs. When stdout matches no known shape, all three are `null` and `$rawOutput` carries the raw stdout.

For scheduler-driven purging, prefer the [`gaze:audit:purge` artisan command](#purging-from-the-scheduler-gazeauditpurge).

## Testing

```php
Gaze::fake();

// Exercise code that calls Gaze::audit()->purge()->...->execute().
Gaze::assertAuditPurged(now()->subDays(90));
```

- `Gaze::assertNothingAudited()` asserts no audit verb was called (purge, export, or safety-net query).
- `Gaze::assertAuditPurgeCount(int)` asserts exact purge call count.
- `Gaze::assertAuditExported(?string $path)` asserts an export happened (optionally to the given output path).
- `$fake->audit()->exportCalls()` / `->safetyNetQueryCalls()` expose recorded calls including the applied filters, keyed by upstream flag name.
- The fake builders record every filter: `$fake->audit()->query()->whereClass('email')->appliedFilters()` returns `['--class' => 'email']`.

To stub return values:

```php
use CertaMesh\Gaze\Audit\AuditPurgeResult;

Gaze::fake(
    auditPurgeHandler: fn (string $beforeIso, bool $dryRun): AuditPurgeResult
        => new AuditPurgeResult(rawOutput: '', count: $dryRun ? 12 : 12),
);
```

## Exceptions

| Class | When |
|---|---|
| `GazeAuditDbNotConfiguredException` | `gaze.audit_db_path` is null or empty and no per-call override was given when an audit verb runs. This is a caller bug; fix config or pass `Gaze::audit($path)`. It is `NonRetryable`. |
| `GazePipelineException` | SQLite open or query failed. Upstream surfaces this as `error=Pipeline, exit=3`. It is retryable; see "Atomicity" above for retry caveats. |
| `GazeAuditPurgeIso8601Exception` | The `--before` value is not parseable as ISO 8601 UTC. |

Other typed exceptions, including `GazeIoException`, `GazeTimeoutException`, and `GazeSigPipeException`, follow the same taxonomy as `clean` / `restore`.

## Query (v0.6.4+)

`Gaze::audit()->query()` returns a `QueryBuilder` that executes `gaze audit query --audit-db=<path>` and parses the TSV output into `list<list<string>>` — each row is a **positional** list of column values, and the **first row is upstream's header line** (the column names). Rows are NOT keyed by string; if you want string-keyed rows, use [`export()`](#export) below, whose JSONL rows are objects keyed by column name.

Every upstream filter flag has a fluent builder method. Filtering happens entirely in the binary — the builder is pure argv forwarding, assembled in upstream `--help` order regardless of call order:

| Builder method | Upstream flag | Notes |
|---|---|---|
| `whereClass(string)` | `--class` | `class()` is a PHP reserved word, hence the `where` prefix (kept consistent for the other value filters). Values like `email`, `name`, `custom:term`. |
| `whereSource(string)` | `--source` | Source recognizer name. |
| `whereAction(string)` | `--action` | `tokenize`, `redact`, or `preserve`. |
| `whereDocumentKind(string)` | `--document-kind` | `text` or `structured`. |
| `from(CarbonInterface\|string)` | `--from` | Rows created at or after. Carbon values are normalised to ISO 8601 UTC Zulu. |
| `to(CarbonInterface\|string)` | `--to` | Rows created at or before. Same normalisation. |
| `whereSession(string)` | `--session` | Opaque audit session id. |
| `hasAmbiguity()` | `--has-ambiguity` | Bare flag: only rows with an ambiguity side-channel record. |
| `whereAmbiguityReason(string)` | `--ambiguity-reason` | e.g. `no-anchor`. |
| `whereCollisionFamily(string)` | `--collision-family` | |
| `whereCollisionVariant(string)` | `--collision-variant` | |
| `onlyRestoreEvents()` | `--restore-events` | Bare flag: only restore telemetry rows. |

```php
use CertaMesh\Gaze\Facades\Gaze;

$rows = Gaze::audit()->query()
    ->whereClass('email')
    ->from(now()->subDays(7))
    ->execute();

[$header, ...$data] = $rows; // first row = column names
```

`gaze audit query --help` is snapshotted under `tests/Contract/__snapshots__/` for the pinned upstream version.

## Export

`export(?string $output = null, string $format = 'jsonl')` on the same builder runs `gaze audit export` and **reuses whatever filter state you accumulated** — upstream applies the identical filter flags to `query` and `export`.

```php
$result = Gaze::audit()->query()
    ->whereClass('email')
    ->from(now()->subDays(30))
    ->export('/backups/audit-email.jsonl');

$result->path;     // '/backups/audit-email.jsonl' (null when exported to stdout)
$result->format;   // 'jsonl'
```

Behaviour pinned against upstream 0.11.x:

- `--format` accepts only `jsonl` upstream; the adapter forwards the value verbatim, so a future upstream `csv` needs no adapter change.
- With `$output`, upstream writes the file itself and prints nothing; the adapter does not read the file back — `rowCount()` returns `null` and `rows()` returns `[]`.
- Without `$output`, the JSONL goes to stdout and is captured on `AuditExportResult::$rawOutput`; `rowCount()` counts the captured lines (upstream reports no count of its own) and `rows()` decodes them into `list<array<string, mixed>>` keyed by column name.

The re-identification warning above applies doubly to exports: an export file is a persistent copy of the audit side channel. Treat it with the same access controls as the audit DB itself and never join it against clean-side response data.

## Safety-Net Query

`Gaze::audit()->safetyNetQuery()` wraps `gaze audit safety-net query` — the leak-suspect metadata rows written by the observer-only safety net (the method is flattened because `query` is upstream's only `safety-net` subcommand). Same TSV shape as `query()`: positional rows, first row is the header.

| Builder method | Upstream flag |
|---|---|
| `whereLeakKind(string)` | `--leak-kind` |
| `whereRawLabel(string)` | `--raw-label` |
| `whereMappedClass(string)` | `--mapped-class` |
| `whereFieldPath(string)` | `--field-path` |
| `from(CarbonInterface\|string)` | `--from` |
| `to(CarbonInterface\|string)` | `--to` |

```php
$rows = Gaze::audit()->safetyNetQuery()
    ->whereMappedClass('email')
    ->from(now()->subDay())
    ->execute();
```

> Note: rows only exist when the binary runs with the safety net enabled (a compile-time feature — see `docs/how-to/safety-net.md`). Against a stock binary the query succeeds and returns just the header row.

## Purging From The Scheduler: `gaze:audit:purge`

The artisan command wraps the purge builder for cron/scheduler use:

```bash
php artisan gaze:audit:purge --before="90 days ago" --dry-run   # preview, no confirmation needed
php artisan gaze:audit:purge --before=2026-01-01T00:00:00Z      # interactive confirmation
php artisan gaze:audit:purge --before="90 days ago" --force     # no confirmation — for schedulers
```

- `--before=` (required) accepts ISO 8601 or any Carbon-parseable relative expression; it is normalised to UTC Zulu before forwarding.
- `--audit-db=` overrides `gaze.audit_db_path` for the run (tenant DBs, one-off maintenance).
- `--dry-run` forwards upstream's `--dry-run` (count without deleting).
- Without `--force` or `--dry-run` the command asks for confirmation and aborts (exit 1) when declined — including non-interactive runs, so a scheduler MUST pass `--force`.
- Exit codes: `0` success, `1` failure (aborted, gaze error, audit DB not configured), `2` invalid input (missing/unparseable `--before`).

```php
// routes/console.php
Schedule::command('gaze:audit:purge', ['--before' => '90 days ago', '--force'])->daily();
```

Upstream purge stdout is pinned (0.11.x): `{"dry_run":bool,"matched":N,"deleted":N}`. `AuditPurgeResult` exposes `matched` and `deleted`, and `count` is the operative number (`matched` on dry runs, `deleted` on real runs).
