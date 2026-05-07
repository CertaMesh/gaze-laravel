# gaze-laravel

Laravel adapter for the [`gaze`](https://github.com/piinuts/gaze) CLI contract.

`gaze-laravel` wraps the pipe-mode `gaze clean` / `gaze restore` workflow. It sends raw UTF-8 text to `clean`, keeps the returned `session_blob` encrypted at rest, and restores model output through `restore` with typed exceptions and queue-aware retry helpers.

**New here?** See [docs/getting-started.md](docs/getting-started.md) for the 10-minute quickstart.

## Requirements

- PHP `^8.2`
- Laravel `^11.0 || ^12.0`
- The `gaze` binary on `PATH`, in `vendor/bin/gaze`, or configured via `GAZE_BINARY`

## Install

```bash
composer require naoray/gaze-laravel
php artisan vendor:publish --tag=gaze-config
php artisan vendor:publish --tag=gaze-policy
```

### Binary install hook

The package ships as a Composer plugin (`Naoray\GazeLaravel\Install\GazeInstallerPlugin`). On first install your Composer will ask whether to allow it — pick `y` to enable automatic binary download, or pick `n` and provide `GAZE_BINARY` yourself. The plugin downloads the pinned `gaze-<target>` binary plus its `.sha256` checksum over HTTPS into `vendor/bin/`.

Binary resolution and install probing both use Symfony `ExecutableFinder` and `Process` — no `shell_exec`. The plugin is therefore container-, Alpine-, and `disable_functions=shell_exec`-safe.

Installer env overrides:

- `GAZE_SKIP_BINARY_DOWNLOAD=1` — skip the download entirely (use when you manage the binary out-of-band)
- `GAZE_VERSION=x.y.z` — install a different gaze version than the one pinned by this release (use cautiously; pinned version is contract-tested)
- `GAZE_RELEASE_BASE=https://...` — release base override for fixture or staging release hosts.

## Config

```php
return [
    'binary' => env('GAZE_BINARY', 'gaze'),
    'timeout_seconds' => (int) env('GAZE_TIMEOUT', 30),
    'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),
    'max_bytes' => env('GAZE_MAX_BYTES'),
    'session_ttl_seconds' => env('GAZE_SESSION_TTL'),
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),
    'audit_db_path' => env('GAZE_AUDIT_DB_PATH'),
    'locale' => env('GAZE_LOCALE'),
    'rulepacks' => env('GAZE_RULEPACKS'),
    'rulepack_paths' => env('GAZE_RULEPACK_PATHS'),
    'safety_net' => env('GAZE_SAFETY_NET', false),
    'safety_net_device' => env('GAZE_SAFETY_NET_DEVICE'),
];
```

`GAZE_ENCRYPTION_KEY` may be unset to reuse `APP_KEY`, or set to a dedicated `base64:` 32-byte key.
The adapter Encrypter cipher matches host `config('app.cipher')` (Laravel default).
Pin the host cipher explicitly if you rotate keys across deploys.

**Additional env vars:** `GAZE_LOCALE` (BCP47 locale hint), `GAZE_RULEPACKS` (comma-separated bundled rulepack names), `GAZE_RULEPACK_PATHS` (comma-separated rulepack TOML paths), `GAZE_SAFETY_NET` (bool, enables secondary classifier pass), `GAZE_SAFETY_NET_DEVICE` (device for safety-net model, e.g. `cuda:0`).

`GAZE_AUDIT_DB_PATH` enables the audit-log SQLite trail: write side via `Gaze::clean()`, read side via `Gaze::audit()->purge()` and the upcoming `query` / `export` verbs. See [docs/audit.md](docs/audit.md).

## Usage

```php
use Naoray\GazeLaravel\Gaze;

public function draft(Gaze $gaze, string $body, string $llmReply): string
{
    $session = $gaze->clean($body);

    // $session->cleanText is safe for the model.
    // $session->ciphertext keeps the session blob encrypted in serialized payloads.
    // $session->detections is forwarded from the CLI stats block.

    return $gaze->restore($session, $llmReply);
}
```

## Blob lifecycle

The session blob (`$session->ciphertext`) is the only thing that lets `restore()` reverse the tokens. Treat it as sensitive: anyone who can read both the blob and a clean LLM response can derive the original PII.

**Where the blob lives between `clean()` and `restore()`:**

- **Default — same request, method scope.** Sync HTTP requests should call `clean()` and `restore()` in the same controller/service method. The `GazeSession` lives on the stack and is GC'd when the request ends.
- **Permitted — encrypted job payload.** Dispatching a queued job that carries the `GazeSession` is fine: the blob is already AES-encrypted under your `GAZE_ENCRYPTION_KEY` (or `APP_KEY`) before serialization. Combine with the Telescope/Pulse exclusion below so the encrypted payload does not get re-logged in plaintext-adjacent telemetry.
- **NOT permitted — cross-request persistence without a threat model.** Do not store `$session->ciphertext` in the user session, request cache, durable cache (Redis/Memcached without TTL alignment), or your application database. Doing so widens the blast radius of any cache/DB compromise from "ciphertext only" to "ciphertext plus the matching clean text it was generated against".

**Threat model:**

- The blob is **request-scoped by default**. It is never serialized to the Laravel session store, the framework cache, or the database by this package.
- The blob is **never written to logs**. Adapter exceptions deliberately omit ciphertext in their `getMessage()` and context arrays.
- The blob is **never shared across tenant boundaries**. If your app is multi-tenant, scope the queue connection and audit DB per tenant; the package does not enforce tenant isolation on your behalf.
- Any persistence layer you add (e.g. resumable wizards, draft autosave) must document why the extended lifetime is safe in your environment and align the cache TTL with `GAZE_SESSION_TTL`.

If you find yourself needing to round-trip a blob through a long-lived store, that is a strong signal you want the upstream persistent-token mode (tracked upstream); raise an issue rather than rolling your own cross-request persistence.

## Livewire — bad vs good

Livewire serializes public component properties to the client between updates. Putting raw PII or the session blob on a public property leaks both surfaces. Here is the same flow shown wrong-then-right.

**~~DO NOT~~ — leaks PII to the wire and persists the blob across updates:**

```php
class DraftReply extends Component
{
    public string $rawEmailBody = '';   // raw PII serialized to client on every update
    public ?GazeSession $session = null; // blob persisted across Livewire round-trips
    public string $reply = '';

    public function generate(Gaze $gaze, Llm $llm): void
    {
        $this->session = $gaze->clean($this->rawEmailBody);
        $this->reply = $llm->complete($this->session->cleanText);
    }

    public function mount(Gaze $gaze): void
    {
        // worse: restoring on mount echoes restored PII back into a public property
        $this->reply = $gaze->restore($this->session, $this->reply);
    }
}
```

**Good — clean/restore inside one action, nothing PII-shaped on the component:**

```php
class DraftReply extends Component
{
    public string $reply = '';

    public function generate(string $rawEmailBody, Gaze $gaze, Llm $llm): void
    {
        $session = $gaze->clean($rawEmailBody);          // method-scoped
        $tokenized = $llm->complete($session->cleanText); // model sees tokens only
        $this->reply = $gaze->restore($session, $tokenized); // restored once, returned
        // $session goes out of scope here; nothing PII-shaped survives the action
    }

    public function render()
    {
        return view('livewire.draft-reply');
    }
}
```

Rules of thumb:

- Raw PII enters as a method argument or comes from an Eloquent relation resolved inside the action — never as a public property.
- `GazeSession` lives on the stack inside the action. It does not become a `public ?GazeSession`.
- Restored output is rendered once. If the user re-edits and re-submits, you call `clean()` + `restore()` again with a fresh blob.

## Conversational-loop guidance

Multi-turn agents (chat UIs, tool-calling loops, planner-executor agents) do not get persistent tokens in the current adapter contract. Each turn produces its own blob. Two patterns work; pick one and stick with it per conversation.

**Pattern A — list of blobs, restore in reverse order on render.**

Each turn appends a `GazeSession` to a per-conversation list. When you render the final assistant message to the user, walk the list newest-to-oldest and restore the surface text against each blob in turn. This handles the case where a token minted in turn 1 reappears verbatim in turn 4's assistant message.

```php
$blobs = []; // ordered, newest-last; encrypted at rest if persisted
foreach ($turns as $turn) {
    $session = $gaze->clean($turn->userInput);
    $blobs[] = $session;
    $turn->modelResponse = $llm->complete($session->cleanText, history: $tokenizedHistory);
}

// On user-visible render of the final assistant message:
$rendered = end($turns)->modelResponse;
foreach (array_reverse($blobs) as $session) {
    $rendered = $gaze->restore($session, $rendered); // most-recent tokens first
}
```

**Pattern B — restore only the final user-visible message.**

Tool-call payloads, intermediate planner thoughts, and any token-shaped text that is fed back into the next LLM turn stay tokenized. You only call `restore()` on the assistant text that is about to render to the human. This keeps the model's context window token-clean and prevents PII from leaking into tool arguments.

**Sharp edges (read these):**

- **Never restore intermediate tool-call payloads to user-visible surfaces.** A tool that takes `customer_email` will get the token; restore inside the tool only if the tool itself is the trust boundary (e.g. it is the email send action). Otherwise restore on the way out, not on the way in.
- **Never `sanitize once, trust forever`.** Each new user input is a new clean call. Reusing an old blob across turns silently misses PII added later in the conversation.
- **Cross-turn token drift.** Token IDs are not guaranteed stable across separate `clean()` invocations. If turn 4 needs to reference an entity from turn 1, prefer Pattern A over manual ID stitching.

If your conversational shape needs persistent tokens (stable across turns, restorable from any later turn), that is upstream tracked work — open an issue describing the shape so adopter friction is captured.

## Enabling NER

By default gaze-laravel runs in regex/rulepack mode. Enable named-entity recognition with:

```bash
php artisan gaze:install-ner --yes
```

This downloads the pinned Davlan mBERT NER int8 ONNX artifact set into `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/`, verifies every file against the upstream `SHA256SUMS` contract, copies the packaged BIO-to-class `labels.json`, and prints the `[ner]` block to paste into `policy.toml`.

To wire `policy.toml` automatically, add `--update-policy`. Re-running the command is idempotent when artifacts already verify.

### Flags

- `--variant=int8` — only `int8` is supported in v0; other variants fail closed.
- `--dest=<abs path>` — override the model storage location.
- `--locale=de` — embed a BCP47 locale hint in the generated `[ner]` block.
- `--check` — verify an existing install without downloading.
- `--dry-run` — preview destination and policy output without writing.
- `--force` — redownload and overwrite even when the destination already verifies.
- `--update-policy` — write the `[ner]` block to `config('gaze.policy_path')`.

### CI / shared-host considerations

Set `HUGGINGFACE_TOKEN` when your host or CI network is rate-limited by HuggingFace. The token is sent as `Authorization: Bearer ...` to HuggingFace artifact requests.

Cache `storage/app/gaze-ner/davlan-mbert-ner-hrl-int8/` between CI jobs when you need NER-enabled integration tests. On locked-down hosts, fetch the artifact set from `onnx-community/bert-base-multilingual-cased-ner-hrl-ONNX`, place it at the destination, and run `php artisan gaze:install-ner --check`.

## Latency baseline / Diagnostic

`Gaze::clean()` currently invokes the upstream `gaze clean` command as a one-shot subprocess for every call. With NER enabled, every invocation loads the NER model from disk before it can return a response. This is the current CLI contract, so repeated calls are not a warm-up run: every `gaze:bench --requests=N` sample pays the full model-load cost.

Use `gaze:bench` to measure your own cold baseline:

```bash
php artisan gaze:bench --requests=10
php artisan gaze:bench --requests=10 --json
```

JSON output includes `bench_schema_version`, `mode: "cold"`, `first_ms`, percentile fields, chronological `samples_ms`, and a small environment fingerprint. For `--requests >= 1000`, samples default to `head` mode (first 100 plus last 100); use `--samples=full` or `--samples=none` when you need a different payload size.

Daemon mode is tracked upstream. Once it ships, this package will gain warm worker-pool support in a follow-up release. Until then, this command is diagnostic only: it establishes a cold-start baseline you can compare across machines, releases, or issue reports.

## Retry Discipline

Consumer jobs must `use Queueable, InteractsWithQueue` traits.

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

class DraftReplyJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(Gaze $gaze): void
    {
        try {
            $session = $gaze->clean($this->payload);
            $draft = $gaze->restore($session, $this->llmReply);
        } catch (\Throwable $e) {
            GazeRetryPolicy::dispatch($e, $this);
        }
    }
}
```

`PolicyOpen` is treated as alert-and-fail, not retryable. Unknown throwables are rethrown to Laravel.

## Exceptions

- Exit bucket `1`: `GazeCallerBugException`
- Exit bucket `2`: `GazeOpsConfigException`
- Exit bucket `3`: `GazeIntegrityException`
- Exit bucket `4`: `GazeInfraException`

Dedicated subclasses include `GazeUnknownTokenException`, `GazeBlobExpiredException`, `GazeInvalidBlobVersionException`, `GazeIoException`, `GazePolicyOpenException`, and `GazeSigPipeException`.

## Operations

`php artisan gaze:check` verifies binary resolution and encrypter wiring.

`php artisan gaze:doctor --deep` adds policy-file checks plus a clean/restore smoke test.

`php artisan gaze:bench --requests=N` measures cold `Gaze::clean()` latency for adopter diagnostics.

Exclude blob-carrying jobs from Telescope and Pulse. Keep ciphertext out of long-lived telemetry stores.

```php
// app/Providers/TelescopeServiceProvider.php
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

public function register(): void
{
    Telescope::filter(function (IncomingEntry $entry) {
        if ($entry->type === 'job' && in_array(
            $entry->content['name'] ?? '',
            [DraftEmailReplyJob::class],
            true,
        )) {
            return false;
        }

        return $this->shouldRecord($entry);
    });
}
```

Apply the same exclusion to Laravel Pulse and any audit-log or breadcrumb tooling that captures queued job payloads.

Prune failed jobs on a cadence aligned with your session TTL:

```php
Schedule::command('queue:prune-failed --hours=24')->daily();
```

## Security model

**What the adapter guarantees:**
- Session blobs are encrypted at rest using Laravel's `Encrypter` (AES-256-GCM by default, keyed on `GAZE_ENCRYPTION_KEY` or `APP_KEY`).
- Restore happens owner-side: the original text is never sent to the model — only `$session->cleanText` (pseudonymized) crosses the model boundary.
- The adapter never logs or stores raw PII; `GazeSession::cleanText` and `GazeSession::ciphertext` are separate fields precisely to prevent accidental exposure.

**What the adapter does not guarantee:**
- Encryption key management: rotate and protect `GAZE_ENCRYPTION_KEY` / `APP_KEY` using your own key management practices.
- Transport security: connections to the LLM provider are outside this adapter's scope.
- Audit DB access control: `GAZE_AUDIT_DB_PATH` points to a SQLite file — OS-level file permissions apply.
- GDPR, DSGVO, or HIPAA compliance: the adapter is designed to support pseudonymization per GDPR Art. 4(5) and related frameworks, but compliance depends on your full data processing context, not this library alone.

## Testing

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint --test
```

Integration tests require a real binary:

```bash
GAZE_BINARY=/path/to/gaze ./vendor/bin/pest --testsuite Integration
```

## License

Apache-2.0 — see [LICENSE](./LICENSE).
