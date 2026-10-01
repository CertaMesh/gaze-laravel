# Upgrading

Canonical migration guide for `certamesh/gaze-laravel`. This file covers the
upcoming release in full; per-minor guides for earlier versions live in
[docs/how-to/upgrading.md](docs/how-to/upgrading.md). Pair with
[CHANGELOG.md](CHANGELOG.md) and the upstream binary's
[UPGRADE.md](https://github.com/CertaMesh/gaze/blob/main/UPGRADE.md).

## v0.15.0 → v0.16.0 (Unreleased)

### TL;DR

1. **Safety-net hits the pipeline protected are amber, not red** (#160). With
   a safety net on (Nym on the release binary), `coverageState()` used to
   return `Suspect` and `hasSuspectedLeak()` `true` for every span the net
   flagged, even after the default `resolve` mode tokenized it. They now
   return `Unverified` / `false`; `$session->leakReport->hasResolvedSuspects()`
   is `true`. `Suspect` stays for spans that may still be raw (`tolerant`, the
   `tolerant` fallback, an upstream `UnactionableSubword`). If you alerted or
   blocked on `Suspect` with `resolve` / `redact`, expect those alerts to stop;
   if you counted `leakReport->suspectCount` as leaks, switch to
   `hasSuspectedLeak()`. A `LeakReport::fromArray()` you build yourself (fakes,
   replays) reads every suspect except a `class_mismatch` as red unless you
   pass the decision (`actsOnSuspects: true`); a `class_mismatch`-only report
   is now amber everywhere.

## v0.14.0 → v0.15.0

> Bug-fix wave on the same gaze 0.15.1 pin. No re-install and no policy edit.
> It is a MINOR release because it adds enum cases and a doctor probe.

### TL;DR

1. **`GAZE_SESSION_SCOPE=ephemeral` now fails fast.** It never worked: gaze
   cannot export an ephemeral session, so every `clean()` failed with a
   *retryable* `GazePipelineException` and queue jobs retried forever. It now
   throws the non-retryable `GazePolicyConfigDetailException` before gaze runs.
   Use `conversation` or `persistent`, or unset the variable. `gaze:doctor`
   fails on the override and warns on a policy-level `ephemeral` scope.
2. **Daemon errors are mapped precisely.** If you caught
   `GazeDaemonTimeoutException`, or matched `DaemonErrorVariant::Timeout` /
   `Unavailable`, to handle the **safety net's** daemon errors, match
   `DaemonErrorVariant::SafetyNetTimeout` / `SafetyNetUnavailable` on
   `GazeDaemonException` instead; the old cases now mean only the adapter's
   own request timeout and a binary without `gaze daemon`. A `match()` on
   `DaemonErrorVariant` needs its `default` arm (11 new cases). Session-id
   mismatches now throw `GazeDaemonTransportException` (a subclass, so
   existing `catch (GazeDaemonException)` blocks still match).
3. **`gaze:proxy:serve --foreground-daemon` works** (it forwarded a flag every
   release binary rejected).
4. **Ignore the old "rebuild with `--features proxy` / `--features daemon`"
   advice.** The release binaries include the proxy, and there never was a
   `daemon` feature. Only the opf safety net needs a custom build
   (`--features safety-net-openai`).

## v0.13.0 → v0.14.0

> Pre-1.0 SemVer: breaking changes land on a MINOR bump. v0.14.0 moves the
> pinned binary from gaze 0.12.0 to 0.15.1 and tracks the upstream contract
> changes that come with it.

### TL;DR

1. **Binary pin `0.12.0` → `0.15.1` — run `php artisan gaze:install --force`.**
   The new binary closes payment-card, IBAN and national-ID leaks that the
   0.12.0 pin shipped raw. If you run a safety-net model, its files must be owned
   by the user that executes gaze. See
   [Binary pin 0.15.1](#binary-pin-0120--0151-what-changes-for-you).
2. **New `GazeSafetyNetUsageException`; `GazeUnsupportedSessionScopeException`
   deprecated.** See [Error variants](#error-variants-safetynetusage-added-unsupportedsessionscope-deprecated).
3. **Kiji safety net removed (BREAKING).** Upstream gaze 0.15.0 deleted the
   Kiji DistilBERT backend; an enabled `kiji-distilbert` backend now fails
   closed before spawning, and every Kiji config key, env var and installer
   option is gone. See [Kiji safety net removed](#kiji-safety-net-removed-breaking).
4. **`--safety-net-backend` is forwarded only when the safety net is enabled.**
   No action needed: `GAZE_SAFETY_NET=false` with a leftover
   `GAZE_SAFETY_NET_BACKEND` keeps the net off, as it did on gaze 0.12.0,
   instead of failing every clean / daemon spawn with `SafetyNetUsage` on
   gaze >= 0.15.0.
5. **Policy leak fix — action required on every existing install.** The
   shipped policy's default rule now tokenizes instead of preserving, but your
   app runs its own copy of `policy.toml`, which the upgrade does not touch.
   Change its last rule (`gaze:doctor` warns until you do); see
   [Published policies: tokenize by default](#published-policies-tokenize-by-default-leak-fix).
6. **Laravel 11 dropped (BREAKING); PHP 8.2 support ends after 2026-12-31.**
   See [Laravel 11 is no longer supported](#laravel-11-is-no-longer-supported-breaking)
   and [PHP 8.2: support ends after 2026-12-31](#php-82-support-ends-after-2026-12-31).

### Binary pin 0.12.0 → 0.15.1: what changes for you

1. **Re-install the binary.** `php artisan gaze:install --force` (or
   `gaze:install:binary --force`). Until you do, `gaze:doctor` warns
   `gaze binary reports v0.12.0 but this package pins v0.15.1`. If you
   provision the binary yourself (`GAZE_BINARY`), take upstream v0.15.1 —
   **not 0.15.0**, which still sends a payment card raw when a CVV, an expiry or
   an order number touches it (upstream #658).
2. **Why it matters.** Through the published policy, 0.12.0 sent these to the
   model raw; 0.15.1 tokenizes them: cards with touching digits, IBANs followed
   by or glued to a `BIC` label, NBSP-grouped IBANs, national IDs under JSON
   keys. With a safety net on, a Resolve+Redact fallback could leave raw text
   behind (upstream #584) — fixed. The upstream `--rulepack-path` no-policy leak
   (#545) and the prefix-cache leak (#579) are fixed in the binary too; the
   adapter never reached them (it always passes `--policy`).
3. **Model ownership.** gaze checks safety-net model bundles against the
   **effective user that runs `gaze`**. For Nym and OPF bundles alike, every file
   and directory must be owned by that user, directories must be mode `0700`,
   files must not be group- or world-writable, and symlinks are refused (OPF
   since 0.13, upstream #422; Nym since 0.15). Under PHP-FPM that user is the
   **pool user** (often `www-data`), not the deploy user who ran `artisan`;
   queue workers and `gaze:daemon:serve` run as their own users. Install the
   bundle as the user that runs gaze. `gaze setup` puts Nym in
   `$XDG_DATA_HOME/gaze/models/nym-small-int8` (its `--model-dir` flag is the
   NER directory, not Nym's) and writes a starter policy whose
   `[safety_net.nym]` table names that directory:

   ```bash
   sudo -u www-data env XDG_DATA_HOME=/srv/gaze vendor/bin/gaze setup \
       --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml
   # bundle: /srv/gaze/gaze/models/nym-small-int8 — copy the [safety_net.nym]
   # table from /tmp/gaze-setup.toml into your policy
   ```

   `gaze setup` refuses a foreign-owned bundle and only repairs modes on
   bundles the current user owns. The NER model `gaze:install:ner` writes is
   not owner-checked by `gaze clean` at this pin, but run the installer as the
   runtime user anyway so a future check doesn't break you.
4. **Behaviour you may notice** (no config change needed):
   - Strict `restore()` no longer throws `GazeUnknownTokenException` on
     identifier-shaped literals such as `Kunde_7` (upstream #473). Placeholders
     from your own or another session still fail closed.
   - A corrupt or truncated NER model now fails `clean()` with
     `GazePipelineException` instead of silently skipping names (#474).
   - `GAZE_LOCALE` no longer hides format-based identifiers: a US-format phone
     number is tokenized under `de-DE` too (#423). To exclude a
     recognizer, disable it in an adopter rulepack. `GAZE_LOCALE` still
     *replaces* the policy's locale chain, so use full tags (`de-DE,en-US`) —
     a bare `de` leaves German national phone numbers raw.
   - Token streams change: one token per entity (e.g. one IBAN token where
     there were two), and `entries` / `detections` count replacements (#628).
     Session blobs created by 0.12.0 still restore on 0.15.1.
   - `gaze:proxy:start` / `:restart` now apply `GAZE_PROXY_POLICY_PATH`,
     `GAZE_PROXY_RULEPACK` and the upstream URLs to the background proxy; 0.12.0
     silently ran it without your policy.
   - Audit DB: the first 0.15 write adds a nullable `restore_trap_shape_count`
     column, and so does `gaze:audit:purge --dry-run`. Rolling back to the
     0.12.0 binary is safe. `export()` rows now carry the `restore_*` fields
     (#555); positional `query()` consumers see one extra column.
   - `schema_version = "0.1"` in a policy is refused (#576). Write `"0.1.0"`,
     or omit the key.
   - Credentials (API keys, tokens) moved to the opt-in `secrets` rulepack
     (#607). The shipped policy never protected them. To opt in, set
     `GAZE_RULEPACKS=core,secrets` — the daemon now honours it too. Never set
     `secrets` alone, and never `none` (gaze ≥ 0.15 accepts it and detects
     nothing): the variable replaces the policy's packs, and `gaze:doctor`
     warns whenever `core` is missing.
5. **Known gaps at the 0.15.1 pin** (upstream known limitations; these reach
   the model raw under every setup): UK national-format phone numbers with a
   leading `0` (e.g. `020 7946 0958`; `+44` numbers are covered); dates of birth
   without a birth-date cue or in `DD.MM.YYYY` after `Geburtsdatum`, and a
   `"dob"` JSON field; repeats of an already tokenized name where no recognizer
   fires; the JWT payload after a `Bearer` cue under `secrets` (#175). Upstream
   tracks them; the adapter adds no PHP-side detection by design.

### Error variants: `SafetyNetUsage` added, `UnsupportedSessionScope` deprecated

gaze 0.15.0 rejects contradictory safety-net flags with a new
`SafetyNetUsage` error (exit 2). The adapter maps it to
`CertaMesh\Gaze\Exceptions\GazeSafetyNetUsageException`, a subclass of
`GazePolicyConfigException` — an existing `catch (GazePolicyConfigException)`
or `catch (GazeOpsConfigException)` still matches, and the queue retry policy
fails the job (`NonRetryable`). Its `detail()` returns the upstream usage
message. Fix the safety-net configuration; retrying cannot help.

`GazeUnsupportedSessionScopeException` (and `Variant::UnsupportedSessionScope`)
is deprecated and never thrown: an invalid `GAZE_SESSION_SCOPE` has always
surfaced as `GazePolicyConfigDetailException` at the adapter's pins. If you
catch the deprecated class, catch `GazePolicyConfigDetailException` instead.
Both stay until 1.0.

### Kiji safety net removed (BREAKING)

> If you construct `GazeOptions` yourself: four parameters were removed from
> the middle of its constructor. Pass arguments **by name** — positional
> arguments after `safetyNetBackend` would shift silently.

Upstream gaze 0.15.0 deleted the Kiji DistilBERT safety net and every
`--kiji-*` flag ([CertaMesh/gaze#612](https://github.com/CertaMesh/gaze/pull/612)).
A 0.15 binary rejects them with a bare `PolicyConfig` error, so v0.14.0:

- forwards no `--kiji-*` flag, whatever Kiji config is left, and drops the
  `gaze.safety_net.kiji.*` group, `gaze.daemon.kiji_distilbert_locales`, the
  Kiji env vars and the four `GazeOptions::$kiji*` properties;
- throws `GazeSafetyNetConfigException` (exit 2) from `Gaze::clean()` /
  `mask()`, `Gaze::daemon()` and `gaze:daemon:serve` **before spawning** when
  the safety net is enabled with `GAZE_SAFETY_NET_BACKEND=kiji-distilbert`.
  `Gaze::restore()` is unaffected — sessions cleaned under Kiji still restore;
- removes `kiji` and `--kiji-model-dir` from `gaze:install:safety-net` and
  `gaze:install` (`--safety-net=kiji` fails without touching `.env`), and the
  `$kijiModelDir` parameter of `SafetyNetConfigurator::pairsFor()`:
  `pairsFor('opf', null, $command, $checkpoint)` becomes
  `pairsFor('opf', $command, $checkpoint)`.

Migration:

1. **Delete the Kiji config.** Remove `GAZE_KIJI_BACKEND`,
   `GAZE_KIJI_DISTILBERT_PRECISION`, `GAZE_KIJI_DISTILBERT_COMMAND`,
   `GAZE_KIJI_DISTILBERT_MODEL_DIR` and `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES`
   from `.env` and your deployment environment. In a published
   `config/gaze.php`, delete the `kiji` block under `safety_net` (or the
   pre-v0.13 flat `kiji_*` keys) and `daemon.kiji_distilbert_locales`.
2. **Pick what replaces it:**
   - **No safety net:** set `GAZE_SAFETY_NET=false` and drop
     `GAZE_SAFETY_NET_BACKEND=kiji-distilbert` (a disabled net no longer
     forwards the selector, but doctor still warns about it).
   - **Nym** (compiled into the release binary):
     1. As the user that runs gaze (PHP-FPM / queue worker), fetch the bundle
        with the adapter's binary: `vendor/bin/gaze setup --safety-net nym`.
     2. Set `GAZE_SAFETY_NET=true` and `GAZE_SAFETY_NET_BACKEND=nym`.
     3. Name the bundle directory in your published policy — the
        `[safety_net.nym]` table `gaze setup` writes into its starter
        `gaze.toml`:

        ```toml
        [safety_net.nym]
        model_dir = "/srv/gaze/gaze/models/nym-small-int8"
        ```

        The adapter always passes `--policy`, so this survives
        `php artisan config:cache`. Alternatively set `GAZE_NYM_MODEL_DIR` in
        the worker's **real process environment** (systemd `Environment=`,
        supervisord `environment=`, container `ENV`, PHP-FPM `env[...]`); a
        `.env` entry alone vanishes under `config:cache`. A policy
        `[safety_net] backend = "nym"` table would turn Nym on even with
        `GAZE_SAFETY_NET=false` — keep the switch in one place.

     First-class Nym config and installer support is tracked in
     [#157](https://github.com/CertaMesh/gaze-laravel/issues/157).
3. **Refresh the config:** `php artisan config:clear` (or re-run
   `php artisan config:cache`).
4. **Run `php artisan gaze:doctor`.** `FAIL` with
   `kiji-distilbert removed in gaze 0.15.0` means an enabled net still selects
   Kiji; a `kiji config … ignored` warning lists leftover keys or env vars to
   delete. On Nym, add `--deep` to exercise the bundle through a real
   clean/restore round-trip.

### Published policies: tokenize by default (leak fix)

The shipped `resources/policy.toml` used to end with a `preserve` default.
Every class the bundled `core` pack detects but no `[[rule]]` names — SSNs,
Steuer-IDs, VAT IDs and crypto addresses already at the 0.12.0 pin; passports,
driver licences, national IDs, NHS/BSN/CPF/CNPJ numbers, dates of birth and
URLs with the gaze 0.15 pin — therefore reached the model **raw**, with a
success exit. gaze ≥ 0.15.0 prints a warning about it on stderr
(`warning: policy preserves N detected classes without a reachable class
rule: …`), but `Gaze::clean()` discards stderr on success, so you will not see
it in your logs.

The shipped policy now ends with:

```toml
[[rule]]
kind = "default"
action = "tokenize"
```

**Every existing install needs this change.** `gaze:install` and
`vendor:publish` copy the policy into your app (`base_path('policy.toml')` by
default, or wherever `GAZE_POLICY_PATH` points), and that copy is what runs —
upgrading the package does not touch it, and `gaze:install --force` keeps it.
`php artisan gaze:doctor` now warns (`policy default … preserve`) while your
copy still falls through to `preserve`. Make the same change in your copy:

```diff
 [[rule]]
 kind = "default"
-action = "preserve"
+action = "tokenize"
```

To keep a class readable on purpose, add an explicit class rule with
`action = "preserve"` **above** the default. Check your copy against the
binary you run. It must be gaze ≥ 0.15.0 — older binaries never print the
warning, so silence would prove nothing:

```bash
vendor/bin/gaze --version   # must report 0.15.0 or newer
echo probe | vendor/bin/gaze clean --policy=/absolute/path/from/GAZE_POLICY_PATH --format=json 2>&1 >/dev/null
```

A `warning: policy preserves …` line means classes still leave raw. An
`{"error":…}` line (e.g. `PolicyOpen` for a wrong path) means the probe did not
run. No output means the policy sends no detected class through raw.

Expect more tokens after the change: URLs become `Custom:url` tokens, and the
ID/date-of-birth classes above are tokenized. Everything restores exactly. One
side effect of upstream's URL recognizer: a URL token runs to the next
whitespace, so in **minified JSON** (`json_encode()` without
`JSON_PRETTY_PRINT`) it also swallows the JSON syntax and the fields after the
URL up to the next space — the model no longer sees them, though restore is
still exact. Pretty-print JSON the model has to read. If you would rather keep
URLs readable, add an explicit rule above the default:

```toml
[[rule]]
kind = "class"
class = "custom:url"
action = "preserve"
```

PII that other recognizers find inside a preserved URL (emails, IPs, …) is
still tokenized on gaze ≥ 0.15, but anything only the URL recognizer would have
covered (e.g. a name in a URL path) then reaches the model raw.

### Laravel 11 is no longer supported (BREAKING)

Laravel 11 reached end of life on 2026-03-12. gaze-laravel v0.14.0 requires
**Laravel 12 or 13** (`illuminate/*: ^12.0|^13.0`). On a Laravel 11 app,
`composer update` keeps you on gaze-laravel 0.13.x. Upgrade Laravel first
(see the [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade)),
then update this package. Nothing else in the adapter changes for Laravel 12/13
users.

### PHP 8.2: support ends after 2026-12-31

PHP 8.2 leaves upstream security support on 2026-12-31. The first gaze-laravel
release after that date will require PHP 8.3+. v0.14.0, and any further release
in 2026, still supports PHP 8.2.

## v0.12.0 → v0.13.0

> Pre-1.0 SemVer: this breaking change lands on a MINOR bump. v0.13.0 removes
> the Composer plugin entirely. `php artisan gaze:install` (and its
> `gaze:install:binary` sub-command) is now the **only** way the `gaze` binary
> is provisioned. Nothing downloads on `composer install` / `composer update`
> anymore. Rationale: explicit over magic, and no `allow-plugins` friction on
> first install.

### TL;DR

1. **The Composer plugin is gone (BREAKING).** `GazeInstallerPlugin` — the
   plugin that auto-downloaded `vendor/bin/gaze` on `composer install`/`update`
   — is removed. The package `type` reverts from `composer-plugin` to `library`.
2. **Migration is two steps** for an app that relied on the auto-download:
   - Remove the now-inert allow-plugins key from your app's `composer.json`:
     ```diff
      "config": {
          "allow-plugins": {
     -        "certamesh/gaze-laravel": true
          }
      }
     ```
     It no longer maps to a plugin, so Composer simply ignores it; deleting it
     just keeps the file honest.
   - Run `php artisan gaze:install` after upgrading. This is the canonical,
     idempotent provisioner (binary + config + policy + optional NER/safety-net),
     ending on a `gaze:doctor` green-check. Run `gaze:install:binary` alone if
     you only want the binary.
3. **Binary pin bumps now need an explicit re-install.** With no plugin, a
   future gaze-laravel release that bumps the pinned binary version will **not**
   fetch the new binary on `composer update`. Re-run
   `php artisan gaze:install --force` (or `gaze:install:binary --force`) to pull
   the newly pinned build. A plain `gaze:install:binary` (no `--force`) is a
   no-op when any runnable `gaze` already resolves — it does not compare the
   installed version against the pin (but `gaze:doctor` now warns about the
   mismatch — see §4).
4. **`gaze:doctor` now warns on a stale pin (but still doesn't fail).** After a
   pin bump, doctor compares the installed binary's reported `--version` against
   the package's pinned version and, on a mismatch, adds a `pinned version`
   detail row plus a warning carrying the exact fix:
   `php artisan gaze:install --force`. It **warns, never fails** — the exit code
   stays `0` as long as a runnable binary and a valid policy/encrypter resolve,
   so a lagging binary won't break a CI gate that runs `gaze:doctor`. The
   warning softens to "expected" and drops the `--force` hint when you have
   deliberately opted out of the pin via `GAZE_BINARY` (an adopter-built binary)
   or `GAZE_VERSION` (a version you pinned yourself). So after a pin bump doctor
   surfaces the gap instead of silently passing — re-run
   `php artisan gaze:install --force` to clear it.
5. **Binary pin `0.11.3` → `0.12.0`** — pure pin-forward, no adopter action
   beyond `php artisan gaze:install --force` (see §3). No CLI contract change;
   the new binary adds a stderr warning on `gaze clean` when a policy leaves a
   collision-family fallback class uncovered (upstream #360) — unreachable with
   the shipped policy, which covers `custom:family:payment-card-or-iban`. If
   you maintain your own policy and see the warning in logs, add the rule it
   names (see the policy-leak sections below).
6. **Published-policy leak fixes — action required if you published the
   policy.** Two never-leak violations fixed in `resources/policy.toml`
   (IBAN collision-family class, symbol-currency `money_amount` pattern). A
   published copy in your app does NOT update itself — apply both edits; see
   the two "Published policies" sections below.
7. **Smaller API-surface breaks** (each detailed in its own section below):
   `SafetyNetConfiguratorResult::$status` is now a backed enum,
   `BinaryInstaller`'s `@internal` static shims moved to `BinaryDownloader`,
   `InstallNerCommand` changed FQCN, `GazeException::$stderrHash` is now
   nullable, and the `RequiresFreshClean` marker interface is deprecated.

### Safety-net config keys are now a nested `safety_net` group (flat keys deprecated)

The shipped `config/gaze.php` now groups the safety-net / OpenAI-privacy-filter
/ Kiji family under one nested `'safety_net' => [...]` array instead of ~14
flat root keys. **Env var names are unchanged** — if you configure gaze purely
through `.env`, there is nothing to do.

Key map (old flat root key → new nested key):

| Deprecated flat key | New nested key |
| --- | --- |
| `safety_net` (bool) | `safety_net.enabled` |
| `safety_net_backend` | `safety_net.backend` |
| `safety_net_device` | `safety_net.device` |
| `safety_net_timeout_ms` | `safety_net.timeout_ms` |
| `safety_net_input_limit_bytes` | `safety_net.input_limit_bytes` |
| `safety_net_mode` | `safety_net.mode` |
| `safety_net_fallback` | `safety_net.fallback` |
| `openai_filter_command` | `safety_net.openai_filter.command` |
| `openai_filter_checkpoint` | `safety_net.openai_filter.checkpoint` |
| `openai_filter_operating_point` | `safety_net.openai_filter.operating_point` |
| `kiji_backend` | `safety_net.kiji.backend` |
| `kiji_distilbert_precision` | `safety_net.kiji.distilbert_precision` |
| `kiji_distilbert_command` | `safety_net.kiji.distilbert_command` |
| `kiji_distilbert_model_dir` | `safety_net.kiji.distilbert_model_dir` |

Backwards compatibility — **nothing breaks either way**:

- **Old published config (flat keys):** keeps working as-is.
  `GazeOptions::fromConfig()` reads the nested keys first and falls back to the
  flat keys, and a flat bool `safety_net` is still understood as the enable
  switch. The flat keys are deprecated and will be dropped no earlier than
  v1.0 — re-publish the config (`php artisan vendor:publish --tag=gaze-config
  --force`) at your leisure.
- **Code that reads the flat keys** (e.g. `config('gaze.safety_net_mode')`):
  keeps working. At registration the provider back-fills each flat key from
  its nested counterpart and collapses `gaze.safety_net` itself back to the
  bool enable switch, so both spellings observe the same values at runtime.
- **Runtime overrides** (`config()->set(...)` in tests or providers): prefer
  the flat keys for now — they win over a nested null and every internal
  reader honours them. Setting a nested key at runtime only affects the
  one-shot `Gaze::clean()` path (which resolves nested-first), not the
  already-back-filled flat mirrors.

Two coercion notes, since `GazeOptions::fromConfig()` is now the single
config→typed-value layer: the config file no longer casts (`(int)`, `(bool)`,
`=== null` ternaries are gone — values ship as raw `env()` reads), and empty
strings normalize to null exactly like before (an empty env var still omits
the CLI flag).

### The service provider is no longer deferred

`GazeServiceProvider` no longer implements `DeferrableProvider` (and its
`provides()` method is gone). The bindings are cheap closures — nothing is
constructed until first resolution — and deferral caused the known gotcha
where `config('gaze.*')` read as `null` during HTTP requests that never
resolved a gaze service. If you referenced `provides()` directly (unlikely),
drop the call. `php artisan about` now also carries a `Gaze` section (binary
path, pinned version, policy path, safety-net switch).

### Constructing `Gaze` manually: new `GazeOptions` constructor (BREAKING)

Only relevant if you `new Gaze(...)` yourself (rare — most code resolves via
the container/facade, which is unchanged):

```php
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\GazeOptions;

// Before (v0.12): 29 scalar constructor params
new Gaze(resolver: $r, process: $p, timeoutSeconds: 30, container: $app,
    policyPath: $path, maxBytes: 1024, safetyNetMode: 'strict', /* … */);

// After (v0.13):
new Gaze(
    resolver: $r,
    process: $p,
    container: $app,
    policyPath: $path,
    options: new GazeOptions(timeoutSeconds: 30, maxBytes: 1024, safetyNetMode: 'strict'),
);

// Or straight from config — this is what the container binding does:
new Gaze($r, $p, $app, $path, GazeOptions::fromConfig(config('gaze')));
```

`Contracts\Gaze` (the facade / injection surface) is untouched.

### `SafetyNetConfiguratorResult::$status` is now a backed enum (BREAKING)

String comparisons against `'written'` / `'unchanged'` stop matching — compare
against `CertaMesh\Gaze\Install\SafetyNetConfigStatus::Written` /
`::Unchanged`, or use `->status->value` for the old strings. The docblocked
`previewed` status is gone from the surface; it was never emitted.

### `BinaryInstaller` slimmed to its real surface (BREAKING)

The ~11 `@internal` delegating static shims (`detectTarget`,
`alreadyInstalled`, `verifyChecksum`, `extract`, `installBinary`, …) that
existed for the removed Composer plugin are deleted; `install()` and
`isProductionEnvironment()` are now private. **Migration:** call the same
methods on `BinaryDownloader`, where the pipeline actually lives. What remains
supported on `BinaryInstaller`: `postInstall(Event)` (the opt-in Composer
script hook), `PINNED_VERSION`, and `resolveReleaseBase()`.

### `InstallNerCommand` moved namespaces (technically BREAKING)

`CertaMesh\Gaze\Console\InstallNerCommand` →
`CertaMesh\Gaze\Console\Install\InstallNerCommand`. The `gaze:install:ner`
signature and the deprecated `gaze:install-ner` alias are unchanged — only
code referencing the class FQCN directly needs the new import.

### `GazeException::$stderrHash` is now nullable

`null` means no subprocess stderr stream ever existed (pre-flight failures,
timeouts, decode failures) — previously these presented `hash('sha256', '')`
as a forensic hash of a stream that never was. A real subprocess failure that
emitted nothing still carries the empty-string hash, so "no stream" and
"empty stream" are distinguishable. If you type-hinted `$e->stderrHash` as
`string`, treat it as `?string`; log-context keys are unchanged
(`stderr_sha256` is `null` in the no-stream case, rendered `none` in
messages).

### `RequiresFreshClean` marker interface deprecated

Branch on `$e instanceof GazeIntegrityException && $e->requiresFreshClean()`
instead of `$e instanceof RequiresFreshClean`. The implementing exceptions
keep the marker until 1.0, so existing `instanceof` checks continue to work.

### Optional: keep the auto-download behaviour, without the plugin

If you preferred the binary landing automatically on every `composer update`,
wire the Composer-context installer into a `post-update-cmd` script in your
app's `composer.json` — explicit, opt-in, no plugin:

```json
"scripts": {
    "post-update-cmd": [
        "CertaMesh\\Gaze\\Install\\BinaryInstaller::postInstall"
    ]
}
```

`GAZE_SKIP_BINARY_DOWNLOAD=1` suppresses that fetch (CI, sandboxes), exactly as
it did for the old plugin. `GAZE_VERSION` and `GAZE_RELEASE_BASE` behave as
before on this path. Note: the `gaze:install:binary` artisan command does **not**
honour `GAZE_SKIP_BINARY_DOWNLOAD` — that toggle is specific to the Composer
script/installer path shown here.

### Published policies: add the `payment-card-or-iban` family rule (leak fix)

Upstream gaze ≥ 0.11.x emits IBAN/credit-card detections whose collision it
cannot resolve (for example when no locale anchor cue is loaded) under the
fail-closed family class `custom:family:payment-card-or-iban` instead of
`custom:iban` / `custom:credit_card`. A policy that only rules on the old
class names — with the usual `preserve` default — lets those IBANs through
**unredacted**.

The shipped `resources/policy.toml` now carries the rule. If you published the
policy into your app (`vendor:publish` or `gaze:install`), add it to your copy:

```toml
[[rule]]
kind = "class"
class = "custom:family:payment-card-or-iban"
action = "tokenize"
```

Whether the family class name is a stable upstream contract is tracked in
[CertaMesh/gaze#360](https://github.com/CertaMesh/gaze/issues/360). Upstream
now warns on `gaze clean` stderr when an uncovered family class could leak.

### Published policies: fix the `money_amount` symbol-boundary pattern (leak fix)

The shipped policy's `money_amount` pattern wrapped its whole alternation in
`\b...\b`. `\b` adjacent to a non-word character (`€`, `$`, `£`) only matches
when flanked by a word character, so the symbol-form branches never matched —
`5000€` and `$3,500.00` passed through **unredacted** in every gaze version
(upstream forensics: [CertaMesh/gaze#361](https://github.com/CertaMesh/gaze/issues/361),
outputs byte-identical from v0.5.2 to v0.11.x). Note for incident bookkeeping:
this was a day-one policy bug, not an upstream regression — if you relied on
symbol-amount redaction, it never worked.

The shipped `resources/policy.toml` now guards only the digit/alpha edges with
`\b` and lets symbols delimit themselves. If you published the policy, replace
your `money_amount` pattern with:

```toml
pattern = '(?i)(?:[€$£]\s?\d+(?:[.,]\d+)*\b|\b(?:EUR|USD|GBP)\s?\d+(?:[.,]\d+)*\b|\b\d+(?:[.,]\d+)*\s?[€$£]|\b\d+(?:[.,]\d+)*\s?(?:EUR|USD|GBP)\b)'
```

Background and the general rewrite rule ("`\b` next to `\d`/`\w` edges only,
never next to the symbol") are documented in upstream
[`docs/reference/policy.md`](https://github.com/CertaMesh/gaze/blob/main/docs/reference/policy.md).

## v0.11.1 → v0.12.0

> Pre-1.0 SemVer: breaking changes land on a MINOR bump. v0.12.0 carries two
> **BREAKING** identity changes — the Composer package name and the PHP root
> namespace — plus additive features. Both breaks are mechanical
> find-and-replace; no runtime behaviour changes with them.

### TL;DR

1. **Package renamed `empiretwo/gaze-laravel` → `certamesh/gaze-laravel`**
   (BREAKING). Swap the requirement and the `allow-plugins` key.
2. **Namespace renamed `Naoray\GazeLaravel` → `CertaMesh\Gaze`** (BREAKING).
   Replace every `use Naoray\GazeLaravel\…` import. Class names inside the
   namespace are unchanged, and the `Gaze` facade alias still works.
3. **Retry markers dropped from `GazeSafetyNetFailureException`** (BREAKING).
   It no longer implements `NonRetryable` / `Retryable` / `RetryableWithAlert`;
   replace any `instanceof` checks against it with `GazeRetryPolicy::classify($e)`
   (or a `HasRetryDisposition` arm). Routing retries through the policy needs no
   change.
4. **Testing fakes implement contracts, not concrete services** (BREAKING for
   tests). If a test type-hints a concrete service and receives a fake, switch
   the hint to the matching `CertaMesh\Gaze\Contracts\*` interface. Application
   code that resolves through the facade or container is unaffected.
5. **New: `leak_report` surfaced as a `GazeSession` trust state** — read
   `coverageState()` / `hasSuspectedLeak()` instead of inferring safety from
   the detection count.
6. **New: per-call NER threshold** — `Gaze::clean($text, threshold: 0.65)`,
   with `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` as the configurable
   default.
7. **Binary pin `0.9.0` → `0.11.3`** — pure pin-forward, no adopter action; the
   clean/restore round trip is byte-identical. Per-pin detail in
   [docs/how-to/upgrading.md](docs/how-to/upgrading.md).

The `[Unreleased]` section of [CHANGELOG.md](CHANGELOG.md) lists further
additive surfaces (`Gaze::mask()`, `php artisan gaze:install`, restore
telemetry, Laravel 13 support). None of those require migration steps.

### 1. Composer package rename (BREAKING)

`gaze-laravel` is published under the CertaMesh identity. The old
`empiretwo/gaze-laravel` package is abandoned on Packagist and points at the
new name; it receives no further releases.

```bash
composer remove empiretwo/gaze-laravel
composer require certamesh/gaze-laravel
```

This package ships a Composer plugin (it downloads the pinned `gaze` binary on
install), so your app's `composer.json` allow-list must track the new name —
otherwise Composer silently skips the plugin and `vendor/bin/gaze` is never
provisioned:

```diff
 "config": {
     "allow-plugins": {
-        "empiretwo/gaze-laravel": true
+        "certamesh/gaze-laravel": true
     }
 }
```

Also update any place the old name is pinned by string: CI caches keyed on the
package name, `composer update empiretwo/gaze-laravel --with-dependencies`
invocations in deploy scripts, Renovate/Dependabot package rules.

### 2. Namespace rename `Naoray\GazeLaravel` → `CertaMesh\Gaze` (BREAKING)

Every class moved from `Naoray\GazeLaravel\…` to `CertaMesh\Gaze\…`. Only the
vendor prefix changed — the sub-namespace and class names are identical, so
the migration is a mechanical replace:

```php
// Before (≤ v0.11.x)
use Naoray\GazeLaravel\Facades\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Queue\GazeRetryPolicy;

// After (v0.12.0)
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\Exceptions\GazeTimeoutException;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
```

One-shot replace across an app:

```bash
grep -rl 'Naoray\\GazeLaravel' app/ tests/ config/ | \
  xargs sed -i '' 's/Naoray\\GazeLaravel/CertaMesh\\Gaze/g'   # macOS; drop '' on Linux
```

What the rename touches — and what it does not:

- **Facade alias — no action.** The `Gaze` alias is auto-discovered from the
  package manifest, so `Gaze::clean()` / `\Gaze::clean()` keep working. Only
  apps that registered the facade FQCN by hand (e.g. an `aliases` entry in
  `config/app.php` pointing at `Naoray\GazeLaravel\Facades\Gaze`) must update
  that string.
- **Service provider — no action.** Auto-discovered. If you disabled discovery
  and listed `Naoray\GazeLaravel\GazeServiceProvider` manually, update it to
  `CertaMesh\Gaze\GazeServiceProvider`.
- **Exception catches.** Update all `catch (\Naoray\GazeLaravel\Exceptions\…)`
  blocks, including bucket parents (`GazeCallerBugException`,
  `GazeInfraException`, …). A stale FQCN in a `catch` does not error — it
  silently stops matching, which bypasses queue retry classification. Grep for
  the old prefix rather than trusting the exception page of your APM.
- **Published config — no action required.** `config/gaze.php` contains no
  class references, and package defaults are merged at runtime, so an already
  published config keeps working. Republish when you want the new keys of this
  release (e.g. `ner_threshold`) documented in your copy:
  `php artisan vendor:publish --tag=gaze-config` (or `--force` to overwrite,
  after diffing your customisations).
- **Queued payloads — drain before deploying.** A serialized `GazeSession`
  (or any queued job holding one) embeds the FQCN in its payload. Jobs
  enqueued under `Naoray\GazeLaravel\GazeSession` will fail to unserialize on
  workers running v0.12.0. Drain those queues before the deploy, or finish
  in-flight jobs on the old release first. Session blobs themselves
  (`ciphertext`) are unaffected — only PHP-serialized wrappers carry the class
  name.
- **`Gaze::fake()` / test doubles.** `Naoray\GazeLaravel\Testing\FakeGaze` →
  `CertaMesh\Gaze\Testing\FakeGaze`, same API.

### 3. Retry markers dropped from `GazeSafetyNetFailureException` (BREAKING)

`GazeSafetyNetFailureException` previously implemented all three static retry
markers at once — `NonRetryable`, `Retryable`, **and** `RetryableWithAlert`
(`CertaMesh\Gaze\Queue\Contracts\*`). The real disposition lives in its
variant-driven `is*()` methods, so a class that carried every marker
simultaneously was ambiguous: any adopter branching on
`$e instanceof NonRetryable` misclassified the retryable safety-net variants
(`Timeout`, `Other`) as terminal and dead-lettered them.

It now implements the new
`CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition` contract
(`retryDisposition(): RetryAction`) and none of the static markers.
`GazeRetryPolicy::classify()` consults `HasRetryDisposition` **before** any
marker interface, so every documented variant classifies exactly as before and
unknown upstream variants keep failing closed to `RetryAction::Fail`.

**Migration — only if you branch on the markers by hand.** If you already route
retries through `GazeRetryPolicy::classify()` (the documented path), you need no
change. Otherwise replace `instanceof`-against-this-exception checks:

```php
// Before (≤ v0.11.x): brittle — the class carried all three markers at once.
use CertaMesh\Gaze\Queue\Contracts\NonRetryable;

if ($e instanceof NonRetryable) {
    $job->fail($e);            // misfired for the Timeout / Other variants
}

// After (v0.12.0), option A — let the policy classify (recommended):
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;

match (GazeRetryPolicy::classify($e)) {
    RetryAction::ReleaseWithBackoff => $job->release($backoff),
    RetryAction::ReleaseWithAlert   => $job->release($backoff), // + fire your infra alert
    RetryAction::Throw              => throw $e,
    RetryAction::Fail               => $job->fail($e),
};

// After (v0.12.0), option B — read the disposition directly:
use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;

if ($e instanceof HasRetryDisposition) {
    $action = $e->retryDisposition();   // RetryAction
}
```

The `is*()` helpers (`isRetryable()`, `isRetryableWithAlert()`,
`isNonRetryable()`) and `safetyNetVariant()` are unchanged. This is a pre-1.0
break; the `HasRetryDisposition` contract freezes at 1.0.

### 4. Service + testing contracts extraction (BREAKING for tests)

Every concrete service now implements a matching interface under
`CertaMesh\Gaze\Contracts` (`Gaze`, `AuditService`, `PurgeBuilder`,
`QueryBuilder`, `DaemonManager`, `DaemonSession`). The container binds the
**contract** canonically and aliases the concrete FQCN to it, so
`app(CertaMesh\Gaze\Gaze::class)` and
`app(CertaMesh\Gaze\Contracts\Gaze::class)` resolve the same singleton, the
`Gaze` facade accessor resolves the contract, and `Gaze::fake()` swaps the
contract binding. Runtime resolution through the facade or the container is
unaffected — this is transparent for application code.

The break is in **tests**. The fakes (`FakeGaze`, `FakeAuditService`,
`FakeDaemonManager`, `FakeDaemonSession`, `FakePurgeBuilder`,
`FakeQueryBuilder`) now *implement the contracts* instead of *extending the
concrete services*, so a fake is no longer `instanceof` the concrete class.

**Migration — only if a test type-hints a concrete service and receives a
fake.** Switch the hint to the contract:

```php
// Before (≤ v0.11.x): fakes extended the concretes, so this accepted a fake.
use CertaMesh\Gaze\Gaze;                 // concrete class
function assertScrubbed(Gaze $gaze): void { /* … */ }

// After (v0.12.0): hint the contract.
use CertaMesh\Gaze\Contracts\Gaze;       // interface
function assertScrubbed(Gaze $gaze): void { /* … */ }
```

The fake call-recording API (`cleanCalls()`, `maskCalls()`, the purge/daemon
assertions) is unchanged. Value objects (`GazeSession`, `EncryptedBlob`,
`Entry`, `CleanResponse`, `LeakReport`) intentionally stay concrete with no
interface — type-hint those directly. As a paid-for-free fix, the fakes no
longer bypass parent constructors (previously that left readonly promoted
properties uninitialised, so any inherited method not overridden fataled with an
uninitialised-typed-property `Error`), and `FakeDaemonManager::client()` now
throws an explicit `LogicException` instead of fataling.

### 5. New: upstream `leak_report` as a `GazeSession` trust state

`Gaze::clean()` previously dropped the upstream `leak_report` — the pipeline's
own coverage check — so callers could only infer safety from the detection
count, which over-asserts (a high count never proves a span did not bleed
through).

```php
// Before (≤ v0.11.x): detection count as a safety proxy — over-asserts.
$session = Gaze::clean($text);
if (count($session->detections) > 0) {
    // "something was redacted" tells you nothing about what was missed
}

// After (v0.12.0): read the pipeline's own verdict.
use CertaMesh\Gaze\CoverageState;

$session = Gaze::clean($text);

match ($session->coverageState()) {
    CoverageState::Verified => $llm->complete($session->cleanText),
    CoverageState::Unverified => $llm->complete($session->cleanText), // no signal — not proof of a leak
    CoverageState::Suspect => throw new DomainException('suspected redaction leak'),
};

if ($session->hasSuspectedLeak()) {
    // convenience boolean for the Suspect state
}
```

Details:

- Additive `?LeakReport $leakReport` field on `GazeSession`; a `null`/absent
  report degrades to `Unverified`, never `Verified`.
- `LeakReport` / `LeakSuspect` are metadata-only (strict field allowlist — no
  source text, no byte offsets).
- **Caveat:** the `Suspect` state depends on the observer-only Pass-3 safety
  net, a compile-time feature absent from the stock release binary — through
  the stock CLI the strongest reachable state is `Unverified`. See
  [docs/reference/upstream-coverage.md](docs/reference/upstream-coverage.md)
  and [docs/explanation/security.md](docs/explanation/security.md).

### 6. New: per-call NER threshold override

`Gaze::clean()` accepts an optional threshold that is forwarded to the binary
as `--ner-threshold=<value>` (inclusive `0.0`–`1.0`).

```php
// Before (≤ v0.11.x): threshold only tunable in policy.toml, per deployment.
$session = Gaze::clean($text);

// After (v0.12.0): tune per call…
$session = Gaze::clean($text, threshold: 0.65);

// …or set an app-wide default (config/gaze.php or env):
// 'ner_threshold' => env('GAZE_NER_THRESHOLD'),   e.g. GAZE_NER_THRESHOLD=0.7
$session = Gaze::clean($text); // uses gaze.ner_threshold when set
```

Precedence: per-call argument > `gaze.ner_threshold` config (env
`GAZE_NER_THRESHOLD`) > policy default (flag omitted). Values outside
`0.0`–`1.0` throw `InvalidArgumentException`. Pure flag forwarding — no
detection logic runs in PHP.

### 7. Binary pin `0.9.0` → `0.11.3`

The pinned upstream `gaze` binary advances from `0.9.0` (the version carried
through the v0.11.x line) to **`0.11.3`**, folding three pin-forwards into this
release (`0.9.0` → `0.11.1` → `0.11.2` → `0.11.3`). Each is a pure pin-forward —
upstream correctness / supply-chain fixes and new default recognizers adopted
purely by taking the binary, with no adapter surface, flag, or wire/default
change; the clean/restore round trip stays byte-identical. `composer install` /
`composer update` re-downloads and SHA256-verifies the pinned binary. Hold the
previous one temporarily with `GAZE_VERSION=0.9.0` while you validate, then
confirm `php artisan gaze:doctor` reports `0.11.3`. Per-pin detail (new
recognizers, the Kiji NER loader fix, the restore token-ordinal tightening)
lives in the `v0.9.0 → v0.11.1`, `v0.11.1 → v0.11.2`, and `v0.11.2 → v0.11.3`
sections of [docs/how-to/upgrading.md](docs/how-to/upgrading.md).

## Earlier versions

Per-minor guides from v0.6.x through v0.11.1 (binary pin bumps, daemon
surface, safety-net backends, rulepack changes) live in
[docs/how-to/upgrading.md](docs/how-to/upgrading.md). Note that those guides
reference the package names current at the time of each release.
