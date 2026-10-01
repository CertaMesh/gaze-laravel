# Proxy

`gaze-laravel` v0.8.1 ships Artisan wrappers for the upstream `gaze proxy`
daemon — a loopback HTTP server that pseudonymizes requests bound for
OpenAI / Anthropic / Gemini before they leave your network and restores
the model's reply on the way back. Zero PII leaves the host.

## TL;DR

```bash
# 1. Install the pinned release binary (it includes the proxy feature)
php artisan gaze:install

# 2. Start the daemon
php artisan gaze:proxy:start

# 3. Point your LLM SDK at http://127.0.0.1:8787 instead of the provider
```

> **On by default.** `proxy` is a default cargo feature upstream since gaze
> 0.8.1, so the binary `gaze:install` downloads and a plain
> `cargo install gaze-cli` both run `php artisan gaze:proxy:*` as is. Only a
> `--no-default-features` build lacks it; `php artisan gaze:doctor` surfaces
> that when it detects proxy configuration against such a binary.

## Config

`config/gaze.php` ships a `proxy` block; each key forwards as an exact
`--flag` to the upstream binary. `null`/empty omits the flag and lets the
binary fall back to its own config file
(`~/.config/gaze/proxy.toml` by default).

| Config key | Env override | Default | Upstream flag |
|---|---|---|---|
| `gaze.proxy.bind` | `GAZE_PROXY_BIND` | `127.0.0.1:8787` | `--bind=` |
| `gaze.proxy.session_ttl` | `GAZE_PROXY_SESSION_TTL` | `30m` | `--session-ttl=` |
| `gaze.proxy.rulepack` | `GAZE_PROXY_RULEPACK` | `core` | `--rulepack=` |
| `gaze.proxy.policy_path` | `GAZE_PROXY_POLICY_PATH` | `null` | `--policy=` |
| `gaze.proxy.upstream.openai` | `GAZE_PROXY_UPSTREAM_OPENAI` | `https://api.openai.com/` | `--upstream-openai=` |
| `gaze.proxy.upstream.anthropic` | `GAZE_PROXY_UPSTREAM_ANTHROPIC` | `https://api.anthropic.com/` | `--upstream-anthropic=` |
| `gaze.proxy.upstream.gemini` | `GAZE_PROXY_UPSTREAM_GEMINI` | `https://generativelanguage.googleapis.com/` | `--upstream-gemini=` |
| `gaze.proxy.stop_timeout` | `GAZE_PROXY_STOP_TIMEOUT` | `10s` | `--timeout=` (stop / restart) |

Duration strings accept `Ns`, `Nm`, `Nh`, or a bare integer (seconds).

## Commands

| Artisan | Upstream | Behaviour |
|---|---|---|
| `php artisan gaze:proxy:serve` | `gaze proxy serve` | Foreground daemon. Blocks. Streams stdout/stderr verbatim. Use in dev or containers. |
| `php artisan gaze:proxy:start` | `gaze proxy start` | Forks a background daemon. Returns once the pidfile is written. |
| `php artisan gaze:proxy:stop` | `gaze proxy stop` | Graceful stop (SIGTERM) with `gaze.proxy.stop_timeout` ceiling. Pass `--force` to escalate to SIGKILL. |
| `php artisan gaze:proxy:restart` | `gaze proxy restart` | Stop + start. Same `--force` / `--timeout` flags as stop. |
| `php artisan gaze:proxy:status` | `gaze proxy status` | Exits `0` when running, `1` when stopped (CI-probe friendly). Pass through binary output. |
| `php artisan gaze:proxy:logs` | `gaze proxy logs` | Dump the proxy log file. Pass `--follow` to tail. |

`start` / `serve` accept artisan-level overrides for the four most-tuned
flags: `--bind=`, `--policy=`, `--rulepack=`, `--session-ttl=`. Each
defaults to the matching `gaze.proxy.*` config key when absent.

## Daemon lifecycle

The upstream daemon is responsible for pidfile management, log rotation,
graceful-shutdown semantics, and adapter state. The adapter does NOT
wrap `gaze proxy install-launchd` / `install-systemd-user`; those
subcommands are upstream stubs in v0.8.0
(they return `"reserved for v0.8.x"`). The corresponding
`php artisan gaze:proxy:install` artisan command will land in a future
adapter minor once upstream implements the integrations.

## Security

Mirrors the upstream
[`gaze-proxy` README security model](https://github.com/CertaMesh/gaze/blob/main/crates/gaze-proxy/README.md):

- **Bind to loopback.** The default `127.0.0.1:8787` is intentional; do
  not expose the proxy on a routable interface. There is no built-in
  auth — anyone with network reach to the bind address can send requests
  and receive de-pseudonymized replies.
- **Auth headers passthrough.** The proxy forwards `Authorization` (and
  any provider-specific API-key header) verbatim to the configured
  upstream. The adapter does not inject auth — your application is still
  responsible for supplying the LLM API key as it would to the provider
  directly.
- **TLS pinning is upstream-owned.** Outbound TLS to the provider uses
  the upstream binary's reqwest stack (rustls, system roots). Adapter
  does not override.
- **Logs and pidfile location** (upstream `gaze-proxy` daemon paths, gaze
  0.15.1): the pidfile is `<data dir>/gaze/proxy.pid` — `$XDG_DATA_HOME` (else
  `~/.local/share`) on Linux, `~/Library/Application Support` on macOS. Logs
  (`proxy.log`, `proxy-stderr.log`) go to `<data dir>/gaze/Logs/` on Linux and
  `~/Library/Logs/gaze/` on macOS; the detached config is
  `<config dir>/gaze/proxy.toml`. Set `XDG_DATA_HOME` / `XDG_CONFIG_HOME` to
  relocate them on Linux.

## Safety nets and refusals (gaze ≥ 0.15)

The proxy has no `--safety-net` flag. It runs the safety nets your **policy**
configures: point `GAZE_PROXY_POLICY_PATH` at a policy with a `[safety_net]`
table. For Nym, append this to a full policy (the snippet alone is not a valid
policy; gaze rejects it with `missing field 'session'`):

```toml
[safety_net]
backend = "nym"

[safety_net.nym]
model_dir = "/srv/gaze/gaze/models/nym-small-int8"
```

With a net configured, each request string goes through three steps before
anything reaches the provider (upstream
[proxy runtime](https://github.com/CertaMesh/gaze/blob/v0.15.1/docs/explanation/proxy/proxy-runtime.md#safety-nets-and-refusals)):

1. The primary pipeline tokenizes what the rules detect.
2. The nets scan the result; every span they flag becomes a restorable token
   (the Resolve step of `gaze clean --safety-net-fallback strict`, upstream
   #660 in 0.15.1).
3. Admission scans once more and **refuses** any raw span a net still flags
   (#585, #593).

The proxy never deletes bytes one way: what step 2 cannot tokenize, or step 3
still flags, is refused. That is stricter than `Gaze::clean()`, whose default
`redact` fallback writes a one-way `[REDACTED:<class>]` marker instead — so the
same text can clean fine and still be refused by the proxy.

A refusal is `422 Unprocessable Entity` and carries the reason, never the text.
Legacy OpenAI and Gemini routes:

```json
{"error": "Refused",
 "refusal": {"error": "Residual", "fallback_reason": "residual_suspect", "suspect_classes": ["name", "location"]}}
```

The Anthropic route returns its usual error envelope with
`"code": "ProtectionRefused"` and the same `refusal` object. `refusal.error` is
the upstream `ProtectionError` (`Residual`, `SafetyNet` = a net failed to run,
`Primary`, `Provenance`, `UnsupportedCoverage`, `EmptyPrimary`);
`fallback_reason` is set when step 2 refused (`residual_suspect`,
`overlap_conflict`, `validator_veto`, `anchor_missing`) and `null` when
admission refused. Each refusal also writes one line,
`gaze-proxy: request refused: {…}`, to the proxy's **stderr**. Under
`gaze:proxy:serve` that is the console. Under `gaze:proxy:start` it is
`proxy-stderr.log` next to `proxy.log` (see [Security](#security) for the
directory). `php artisan gaze:proxy:logs` reads only `proxy.log`, so it does not
show refusals.

Handling it in your app:

- Treat `422` + `Refused` / `ProtectionRefused` as a **content** refusal: the
  same text will be refused again, so do not retry it unchanged. Route it like a
  policy violation (ask the user to rephrase, or clean it through
  `Gaze::clean()` first and send the clean text).
- `refusal.error = "SafetyNet"` means the net itself failed at request time
  (timeout, runtime error); that one is an operations problem — check
  `proxy-stderr.log` and `gaze:doctor`. A missing or broken Nym bundle never
  gets that far: `gaze proxy start` fails with `SafetyNetConfig` instead.
- Before gaze 0.15.1 the proxy answered these cases with `500 {"error":"Pipeline"}`
  (legacy) or `502 InvalidToken` (Anthropic) and refused every request a net
  flagged at all; clients matching those codes must switch to `422`.
- Without a configured net, steps 2 and 3 do nothing; spans no rule detects
  are forwarded raw, exactly as `gaze clean` prints them.

## Doctor probe

`php artisan gaze:doctor` probes `gaze proxy --help` whenever it detects
adopter-set proxy configuration (any deviation from the package's default
`gaze.proxy.*` block). Possible outcomes:

- **No probe.** All `gaze.proxy.*` keys at defaults — adopter is not
  using proxy. Doctor stays silent on proxy.
- **`gaze proxy feature available`.** The configured binary includes the
  proxy feature (release binaries and default builds do). Proxy commands will work.
- **`gaze proxy not available — this binary was built without the proxy
  feature ...`** The configured binary is a `--no-default-features` build.
  Rebuild with default features (`cargo install gaze-cli`, plus
  `--features safety-net-openai` if you use opf), or unset `GAZE_BINARY` and run
  `php artisan gaze:install:binary --force` for the release binary.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — full upstream-flag
  ↔ Laravel-surface mapping.
- [Upstream `gaze-proxy` README](https://github.com/CertaMesh/gaze/blob/main/crates/gaze-proxy/README.md) — daemon internals, adapter contract, request/response shape.
- [Upstream proxy-runtime architecture](https://github.com/CertaMesh/gaze/blob/main/docs/explanation/proxy/proxy-runtime.md) — tokio runtime, adapter trait, request lifecycle.
