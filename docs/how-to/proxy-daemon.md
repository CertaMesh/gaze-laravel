# Proxy

`gaze-laravel` v0.8.1 ships Artisan wrappers for the upstream `gaze proxy`
daemon — a loopback HTTP server that pseudonymizes requests bound for
OpenAI / Anthropic / Gemini before they leave your network and restores
the model's reply on the way back. Zero PII leaves the host.

## TL;DR

```bash
# 1. Rebuild upstream with the proxy feature (one-time per host)
cargo install gaze-cli --features proxy

# 2. Start the daemon
php artisan gaze:proxy:start

# 3. Point your LLM SDK at http://127.0.0.1:8787 instead of the provider
```

> **Opt-in upstream feature.** The published GitHub-release `gaze` binary
> asset is built **without** `--features proxy`, so the
> `php artisan gaze:proxy:*` commands will fail with
> `unrecognized subcommand 'proxy'` against a stock install. Run
> `cargo install gaze-cli --features proxy` (and set `GAZE_BINARY` if you
> install outside `vendor/bin/`) to enable it.
>
> `php artisan gaze:doctor` will surface the exact hint when it detects
> proxy configuration against a binary that lacks the feature.

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
- **Logs and pidfile location.** Both live under
  `$XDG_STATE_HOME/gaze-proxy/` (Linux) or
  `~/Library/Application Support/gaze-proxy/` (macOS) per the upstream
  daemon-paths contract. Set `XDG_STATE_HOME` to relocate.

## Doctor probe

`php artisan gaze:doctor` probes `gaze proxy --help` whenever it detects
adopter-set proxy configuration (any deviation from the package's default
`gaze.proxy.*` block). Possible outcomes:

- **No probe.** All `gaze.proxy.*` keys at defaults — adopter is not
  using proxy. Doctor stays silent on proxy.
- **`gaze proxy feature available`.** The binary on `PATH` is built with
  `--features proxy`. Daemon commands will work.
- **`gaze proxy not available — rebuild upstream binary with: cargo
  install gaze-cli --features proxy. ...`** The configured binary lacks
  the feature. `php artisan gaze:proxy:*` will fail with
  `unrecognized subcommand 'proxy'` until you rebuild.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — full upstream-flag
  ↔ Laravel-surface mapping.
- [Upstream `gaze-proxy` README](https://github.com/CertaMesh/gaze/blob/main/crates/gaze-proxy/README.md) — daemon internals, adapter contract, request/response shape.
- [Upstream proxy-runtime architecture](https://github.com/CertaMesh/gaze/blob/main/docs/architecture/proxy-runtime.md) — tokio runtime, adapter trait, request lifecycle.
