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
- `Gaze::audit()->purge()` and future `query` / `export` verbs read from the same DB.

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
use Naoray\GazeLaravel\Facades\Gaze;

// Dry-run: count matching rows without deleting.
$preview = Gaze::audit()
    ->purge()
    ->before(now()->subDays(90))
    ->dryRun();

echo $preview->count(); // ?int: null if upstream stdout shape is not recognized
echo $preview->rawOutput(); // raw stdout for inspection

// Real delete. Schedule via queue or cron.
$result = Gaze::audit()
    ->purge()
    ->before(now()->subDays(90))
    ->execute();
```

`before()` accepts either a `CarbonInterface` or an ISO 8601 string. Carbon values are converted to UTC Zulu (`...Z`) before forwarding.

> Warning: `execute()` deletes rows. Call `dryRun()` first when uncertain. The package does not fall back to dry-run if `execute()` is called by mistake.

`AuditPurgeResult::count()` is nullable. v0.5.0 purge stdout is not yet contract-snapshotted in this repo because fixture-DB infrastructure is needed to capture both empty and populated cases. When the regex does not match, `count()` returns `null` and `rawOutput()` carries the raw stdout. A follow-up PR will tighten this once stdout is pinned.

## Testing

```php
Gaze::fake();

// Exercise code that calls Gaze::audit()->purge()->...->execute().
Gaze::assertAuditPurged(now()->subDays(90));
```

- `Gaze::assertNothingAudited()` asserts no audit verb was called.
- `Gaze::assertAuditPurgeCount(int)` asserts exact purge call count.

To stub return values:

```php
use Naoray\GazeLaravel\Audit\AuditPurgeResult;

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

## Query (v0.6.5+)

`Gaze::audit()->query()` returns a `QueryBuilder` that executes `gaze audit query --audit-db=<path>` and parses TSV output into `list<list<string>>`. See `gaze audit query --help` (snapshotted under `tests/Contract/__snapshots__/`) for filter options.

`export()` is tracked upstream and will be added in a follow-up release.
