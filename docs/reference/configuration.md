# Configuration Reference

All configuration lives in `config/gaze.php`. Publish it with:

```bash
php artisan vendor:publish --tag=gaze-config
```

---

## Complete `.env` Example

```dotenv
# Required for production — leave blank for auto-discovery via vendor/bin/gaze
GAZE_BINARY=

# Process timeout. Increase if you process very large documents.
GAZE_TIMEOUT=30

# Path to your TOML policy file. Defaults to <project-root>/policy.toml.
GAZE_POLICY_PATH=/var/www/html/policy.toml

# Optional hard ceiling on input size (bytes). Defaults to 10 MB if unset.
GAZE_MAX_BYTES=

# Optional session blob TTL forwarded to the binary.
GAZE_SESSION_TTL=

# Optional clean session isolation scope: conversation or persistent (not ephemeral).
GAZE_SESSION_SCOPE=

# Optional dedicated encryption key for session blobs.
# Must be prefixed with "base64:" followed by a base64-encoded 32-byte value.
# Generate: php artisan key:generate --show | sed 's/base64://' | base64 -d | base64 | awk '{print "base64:"$1}'
GAZE_ENCRYPTION_KEY=

# Path to a SQLite audit log. Leave blank to disable audit logging.
GAZE_AUDIT_DB_PATH=/var/www/html/storage/app/gaze/audit.sqlite

# OpenAI privacy-filter safety-net knobs.
GAZE_SAFETY_NET=false
GAZE_SAFETY_NET_DEVICE=cpu
GAZE_OPENAI_FILTER_COMMAND=/usr/local/bin/opf
GAZE_OPENAI_FILTER_CHECKPOINT=/var/www/html/storage/app/gaze/openai-filter
GAZE_OPENAI_FILTER_OPERATING_POINT=balanced
GAZE_SAFETY_NET_TIMEOUT_MS=5000
GAZE_SAFETY_NET_INPUT_LIMIT_BYTES=1048576
GAZE_SAFETY_NET_MODE=strict

# Optional restore behavior: strict or tolerant.
GAZE_RESTORE_MODE=
```

---

## Keys

### `gaze.binary`

| | |
|---|---|
| **Env var** | `GAZE_BINARY` |
| **PHP type** | `string\|null` |
| **Default** | `null` (auto-discover) |

Path or executable name for the `gaze` binary.

**Resolution order (when null or unset):**

1. `vendor/bin/gaze` — installed automatically by the Composer plugin.
2. First `gaze` found on `$PATH`.

**When to set:** In production, set an absolute path to guarantee deterministic resolution. A bare executable name (e.g. `gaze`) skips the `vendor/bin` fallback and is not recommended.

**Example:**

```dotenv
GAZE_BINARY=/usr/local/bin/gaze
```

---

### `gaze.timeout_seconds`

| | |
|---|---|
| **Env var** | `GAZE_TIMEOUT` |
| **PHP type** | `int` |
| **Default** | `30` |

Hard wall-clock timeout (seconds) for any single `gaze` process invocation. A hung process is killed rather than allowed to tie up a queue worker.

**When to set:** Increase if you routinely process documents that are close to the 10 MB ceiling and your hardware is slow. Decrease in latency-sensitive pipelines where you want fast failure.

**Example:**

```dotenv
GAZE_TIMEOUT=60
```

**Caveat:** When the timeout fires, a `GazeTimeoutException` is thrown. That exception implements `RetryableWithAlert`, so `GazeRetryPolicy` will re-queue the job and fire a `GazeInfraAlert` event. Repeated timeouts indicate hardware or sizing problems, not caller bugs.

---

### `gaze.policy_path`

| | |
|---|---|
| **Env var** | `GAZE_POLICY_PATH` |
| **PHP type** | `string` |
| **Default** | `base_path('policy.toml')` |

Absolute path to the TOML detector policy file passed to `gaze clean`. Publish the example policy with:

```bash
php artisan vendor:publish --tag=gaze-policy
```

**When to set:** Always set this in production to an absolute path. The default (`<project-root>/policy.toml`) works for local development. Multi-tenant applications that use tenant-specific policies should use per-request overrides at the application layer rather than a global config change.

**Example:**

```dotenv
GAZE_POLICY_PATH=/var/www/html/policy.toml
```

**Caveat:** An empty string (`""`) causes a `RuntimeException` at call time, not boot time. Set a valid path or leave it at the default.

---

### `gaze.max_bytes`

| | |
|---|---|
| **Env var** | `GAZE_MAX_BYTES` |
| **PHP type** | `int\|null` |
| **Default** | `null` (10 485 760 bytes = 10 MB enforced by the library) |

Optional explicit ceiling on input size passed to the binary via `--max-bytes`. When null, the library still enforces a pre-flight check at 10 MB (the v0.3 default) before the process is spawned.

**When to set:** Set a lower value to fail fast on unexpectedly large payloads in consumer pipelines. The pre-flight check runs client-side before the process is spawned, so oversized inputs throw `GazeInputTooLargeException` (a `NonRetryable` caller-bug exception) without incurring process-spawn overhead.

**Example:**

```dotenv
GAZE_MAX_BYTES=1048576
```

---

### `gaze.session_ttl_seconds`

| | |
|---|---|
| **Env var** | `GAZE_SESSION_TTL` |
| **PHP type** | `int\|null` |
| **Default** | `null` (binary default) |

Optional session blob TTL forwarded to the binary as `--session-ttl`. Controls how long a session blob remains valid for `gaze restore` calls. When null, the binary applies its own default.

**When to set:** Set when your workflow spans a significant time window between `clean` and `restore`. For example, if you store session blobs in a job payload that may be delayed hours in a high-backpressure queue, set a TTL that matches your maximum acceptable queue latency.

**Example:**

```dotenv
# 2-hour TTL
GAZE_SESSION_TTL=7200
```

**Caveat:** Expired blobs throw `GazeBlobExpiredException` on `restore`. That exception is `NonRetryable` and its `requiresFreshClean()` method returns `true`, signalling that the only recovery is to re-run `clean` on the original text.

---

### `gaze.session_scope`

| | |
|---|---|
| **Env var** | `GAZE_SESSION_SCOPE` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default) |

Optional session isolation scope forwarded to `gaze clean` as `--session-scope=<value>`. Supported values are `conversation` and `persistent`. Null omits the flag and uses the policy's `[session] scope` (the shipped policy sets `persistent`).

**When to set:** Use `conversation` when multiple turns share a short-lived context, and `persistent` when durable token/session behavior is intentional.

**Example:**

```dotenv
GAZE_SESSION_SCOPE=conversation
```

**`ephemeral` is not supported through `Gaze::clean()`.** Upstream accepts the value, but `gaze clean` must return the session blob that `restore()` needs, and gaze never exports an ephemeral session by design (`Session::export()` returns `ExportForbidden`). The binary reports that as the generic `{"error":"Pipeline","exit":3}`, which maps to the *retryable* `GazePipelineException`, so a queue job would retry forever. The adapter therefore rejects `ephemeral` (any case, surrounding whitespace ignored) before spawning the binary with a non-retryable `GazePolicyConfigDetailException` (exit 2, `stderrHash` null). `gaze:doctor` fails on it too.

A policy file with `[session] scope = "ephemeral"` is refused the same way when `GAZE_SESSION_SCOPE` is unset (or empty): `Gaze::clean()` reads the policy's `[session] scope` before spawning and throws the same non-retryable `GazePolicyConfigDetailException` (its message names the policy scope instead of the override). The match is exact, like upstream's: a policy `"Ephemeral"` is an invalid value, and the binary rejects it with its own `PolicyConfig` detail. The read is cached per process by policy path and file fingerprint (modification time, change time, size, inode, device): a long-lived worker (Octane, `queue:work`) pays one `stat()` per clean and picks up a policy edit on the next call. Under PHP-FPM the cache lives for one request, so the first clean of a request reads the file — and skips the TOML parse unless the file contains `ephemeral`. A read or parse failure is never cached, so fixing a permission problem takes effect immediately. A policy the adapter cannot read or parse is not reported by this check: the binary still answers with its own `GazePolicyOpenException` / `GazePolicyConfigException`. `gaze:doctor` warns about an ephemeral policy scope without failing. Set `scope = "conversation"` or `"persistent"` in the policy, or set `GAZE_SESSION_SCOPE` to one of them: the override wins over the policy, as `--session-scope` does upstream. The daemon (`Gaze::daemon()`) is not affected: it takes no `--session-scope`, never exports a blob, and runs fine with an ephemeral policy.

**Caveat:** Unknown values are rejected by the binary and surface as `GazePolicyConfigDetailException` (`detail()`: `session.scope must be one of ephemeral, conversation, persistent, got …`). Upstream matches case-sensitively, so `Conversation` is rejected too.

---

### `gaze.rulepacks`

| | |
|---|---|
| **Env var** | `GAZE_RULEPACKS` (comma-separated) |
| **PHP type** | `list<string>` |
| **Default** | `[]` (the policy's `[policy.rulepacks] bundled` list applies) |

Bundled rulepack names forwarded to `gaze clean` and `gaze daemon` as one `--rulepack-bundled=<name>` flag each. Upstream ships `core`, `locale-de`, `locale-en`, `locale-br`, `locale-fr`, `locale-in`, `locale-nl`, `locale-uk` and `secrets`. **Do not use `none`:** since gaze 0.15 it is accepted and disables every bundled pack, so cleans succeed with no detection (gaze 0.12 rejected it).

**When to set:** To opt into the credential recognizers (API keys, security tokens) that gaze 0.15.0 moved out of `core` into the opt-in `secrets` pack (upstream #607).

**Example:**

```dotenv
GAZE_RULEPACKS=core,secrets
```

**Caveat:** The list **replaces** the policy's `bundled` list instead of extending it. `GAZE_RULEPACKS=secrets` alone drops `core`, and IBANs, cards, emails and phone numbers reach the model raw. Always keep `core` in the list — `gaze:doctor` warns when it is missing. Both `Gaze::clean()` and the daemon (`Gaze::daemon()`, `gaze:daemon:serve`) forward this key. Known gap in the upstream `secrets` pack: after a cue word (`Bearer eyJ…`) only the JWT header is tokenized; payload and signature stay raw (#175).

---

### `gaze.rulepack_paths`

| | |
|---|---|
| **Env var** | `GAZE_RULEPACK_PATHS` (comma-separated) |
| **PHP type** | `list<string>` |
| **Default** | `[]` |

Filesystem paths to custom rulepack TOML files, forwarded (one-shot and daemon) as one `--rulepack-path=<path>` flag each. Since gaze 0.15.0 (#632) custom paths keep the `core` floor unless the policy sets `bundled = []` or you pass `GAZE_RULEPACKS=none`.

**Example:**

```dotenv
GAZE_RULEPACK_PATHS=/etc/gaze/tenant.toml
```

**Caveat:** The classes a custom pack emits follow the policy's rules. The shipped policy tokenizes every class it does not name, so you only need explicit `[[rule]]` entries for classes you want to `preserve`.

---

### `gaze.blob_encryption_key`

| | |
|---|---|
| **Env var** | `GAZE_ENCRYPTION_KEY` |
| **PHP type** | `string\|null` |
| **Default** | `null` (uses Laravel `APP_KEY` via the default `Crypt` facade) |

Optional dedicated encryption key for session blobs stored in `GazeSession::$ciphertext`. When null, `EncryptedBlob` falls back to Laravel's application encrypter keyed on `APP_KEY`.

**When to set:** Set a separate key when you want to rotate session-blob encryption independently from `APP_KEY`, or when you run multiple applications that share storage (e.g. a queue worker and a web process with different `APP_KEY` values).

**Format:** The value **must** use a `base64:` prefix followed by a base64-encoded 32-byte random string — the same format that `php artisan key:generate` produces for `AES-256-CBC`. The prefix is stripped before decoding.

**Example:**

```dotenv
GAZE_ENCRYPTION_KEY=base64:YOUR_BASE64_ENCODED_32_BYTE_KEY_HERE==
```

**Generate a fresh key:**

```bash
# Via PHP (cross-platform)
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

**Caveats:**

- If the value is set but is not a valid base64-encoded 32-byte string, the service provider throws a `RuntimeException` at boot — deliberately loud.
- Session blobs encrypted with one key cannot be decrypted with another. Rotating this key invalidates all in-flight session blobs. Drain queues before rotating.
- The cipher used matches `app.cipher` (default `AES-256-CBC`).

---

### `gaze.audit_db_path`

| | |
|---|---|
| **Env var** | `GAZE_AUDIT_DB_PATH` |
| **PHP type** | `string\|null` |
| **Default** | `null` (audit logging disabled) |

Optional path to a SQLite database where `gaze` writes redaction audit events. When null, `Gaze::clean()` runs without audit logging and all `Gaze::audit()` verb calls throw `GazeAuditDbNotConfiguredException`.

**When to set:** Set in any environment that must maintain a redaction trail for retention compliance. The binary creates the SQLite file on first write — do not pre-create it.

**Per-call override:** `Gaze::audit('/path/to/other.sqlite')->purge()` wins over this config value. This is useful for tenant-isolated audit DBs.

**Example:**

```dotenv
GAZE_AUDIT_DB_PATH=/var/www/html/storage/app/gaze/audit.sqlite
```

**Caveats:**

- Both the web process and the queue worker must have read/write access to the file. The binary creates files in mode `0600`; widen permissions via deploy tooling if processes run under different OS users.
- Audit logging is non-transactional with the clean response. A successful `clean` may occasionally omit an audit row. Treat audit rows as advisory and reconcile on a schedule if complete-trail guarantees are required.
- Do not cross-join audit rows with `GazeSession::$cleanText`. The `recognizer_id`, `pii_class`, and token slot fields in audit rows are re-identification side channels. See [audit.md](../how-to/audit-query-export.md) for the full atomicity and re-identification warning.

---

### `gaze.safety_net`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET` |
| **PHP type** | `bool` |
| **Default** | `false` |

Enables the OpenAI privacy-filter safety net. When true, `Gaze::clean()` forwards `--safety-net=openai-filter`.

**Example:**

```dotenv
GAZE_SAFETY_NET=true
```

---

### `gaze.safety_net_backend`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET_BACKEND` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default: `openai-filter`) |

Optional safety-net backend selector, forwarded as `--safety-net-backend=<value>` **only while `gaze.safety_net` is true** (gaze >= 0.15.0 rejects a lone selector, so a disabled net with a leftover backend stays off). Valid upstream values are `openai-filter` (needs a gaze binary built with upstream's `safety-net-openai` feature) and `nym` (gaze >= 0.15.0, compiled into the release binary; name the bundle directory in the policy's `[safety_net.nym] model_dir`, or in `GAZE_NYM_MODEL_DIR` in the worker's real process environment — see [SafetyNet](../how-to/safety-net.md#kiji-was-removed-upstream-in-gaze-0150)).

**Caveat:** `kiji-distilbert` was removed upstream in gaze 0.15.0. With the net enabled it fails closed before the binary is spawned (`GazeSafetyNetConfigException`) and `gaze:doctor` fails. The Kiji env vars — `GAZE_KIJI_BACKEND`, `GAZE_KIJI_DISTILBERT_PRECISION`, `GAZE_KIJI_DISTILBERT_COMMAND`, `GAZE_KIJI_DISTILBERT_MODEL_DIR`, `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES` — were removed in v0.14.0; leftover values are ignored and `gaze:doctor` warns.

**Example:**

```dotenv
GAZE_SAFETY_NET=true
GAZE_SAFETY_NET_BACKEND=nym
```

---

### `gaze.safety_net_device`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET_DEVICE` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default) |

CUDA/CPU device for the OpenAI privacy-filter safety net, forwarded as `--openai-filter-device=<value>`.

**Example:**

```dotenv
GAZE_SAFETY_NET_DEVICE=cpu
```

---

### `gaze.openai_filter_command`

| | |
|---|---|
| **Env var** | `GAZE_OPENAI_FILTER_COMMAND` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary uses PATH lookup) |

Optional path to the local `opf` command used by the OpenAI privacy-filter safety net. Forwarded to `gaze clean` as `--openai-filter-command`.

**Example:**

```dotenv
GAZE_OPENAI_FILTER_COMMAND=/usr/local/bin/opf
```

---

### `gaze.openai_filter_checkpoint`

| | |
|---|---|
| **Env var** | `GAZE_OPENAI_FILTER_CHECKPOINT` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default) |

Optional model checkpoint directory for the OpenAI privacy-filter safety net. Forwarded to `gaze clean` as `--openai-filter-checkpoint`.

**Example:**

```dotenv
GAZE_OPENAI_FILTER_CHECKPOINT=/var/www/html/storage/app/gaze/openai-filter
```

---

### `gaze.openai_filter_operating_point`

| | |
|---|---|
| **Env var** | `GAZE_OPENAI_FILTER_OPERATING_POINT` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default) |

Optional sensitivity trade-off for the OpenAI privacy-filter safety net. Valid upstream values are `high-recall`, `balanced`, and `high-precision`. Forwarded to `gaze clean` as `--openai-filter-operating-point`.

**Example:**

```dotenv
GAZE_OPENAI_FILTER_OPERATING_POINT=balanced
```

---

### `gaze.safety_net_timeout_ms`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET_TIMEOUT_MS` |
| **PHP type** | `int\|null` |
| **Default** | `null` (binary default: 5000 ms) |

Optional subprocess timeout for the OpenAI privacy-filter safety net, in milliseconds. Forwarded to `gaze clean` as `--safety-net-timeout-ms`.

**Example:**

```dotenv
GAZE_SAFETY_NET_TIMEOUT_MS=5000
```

---

### `gaze.safety_net_input_limit_bytes`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` |
| **PHP type** | `int\|null` |
| **Default** | `null` (binary default: 1048576 bytes) |

Optional clean-text size cap for the OpenAI privacy-filter safety-net subprocess. Forwarded to `gaze clean` as `--safety-net-input-limit-bytes`.

**Example:**

```dotenv
GAZE_SAFETY_NET_INPUT_LIMIT_BYTES=1048576
```

---

### `gaze.safety_net_mode`

| | |
|---|---|
| **Env var** | `GAZE_SAFETY_NET_MODE` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default: `strict`) |

Optional suspected-leak handling mode for the OpenAI privacy-filter safety net. Valid upstream values are `strict` and `tolerant`. Forwarded to `gaze clean` as `--safety-net-mode`.

**Example:**

```dotenv
GAZE_SAFETY_NET_MODE=strict
```

---

### `gaze.restore_mode`

| | |
|---|---|
| **Env var** | `GAZE_RESTORE_MODE` |
| **PHP type** | `string\|null` |
| **Default** | `null` (binary default: `strict`) |

Optional restore behavior for unknown tokens, forwarded to `gaze restore` as `--restore-mode=<value>`. Valid upstream values are `strict` and `tolerant`.

**When to set:** Leave null for strict restore failures on unknown tokens. Use `tolerant` only when partial restore is preferable to failing the whole operation.

**Example:**

```dotenv
GAZE_RESTORE_MODE=tolerant
```
