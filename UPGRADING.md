# Upgrading

Canonical migration guide for `certamesh/gaze-laravel`. This file covers the
upcoming release in full; per-minor guides for earlier versions live in
[docs/how-to/upgrading.md](docs/how-to/upgrading.md). Pair with
[CHANGELOG.md](CHANGELOG.md) and the upstream binary's
[UPGRADE.md](https://github.com/CertaMesh/gaze/blob/main/UPGRADE.md).

## v0.11.1 → v0.12.0 (Unreleased)

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
3. **New: `leak_report` surfaced as a `GazeSession` trust state** — read
   `coverageState()` / `hasSuspectedLeak()` instead of inferring safety from
   the detection count.
4. **New: per-call NER threshold** — `Gaze::clean($text, threshold: 0.65)`,
   with `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` as the configurable
   default.

The `[Unreleased]` section of [CHANGELOG.md](CHANGELOG.md) lists further
additive surfaces (`Gaze::mask()`, `php artisan gaze:install`, restore
telemetry, Laravel 13 support, binary pin `0.9.0` → `0.11.1`). None of them
require migration steps.

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

### 3. New: upstream `leak_report` as a `GazeSession` trust state

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

### 4. New: per-call NER threshold override

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

## Earlier versions

Per-minor guides from v0.6.x through v0.11.1 (binary pin bumps, daemon
surface, safety-net backends, rulepack changes) live in
[docs/how-to/upgrading.md](docs/how-to/upgrading.md). Note that those guides
reference the package names current at the time of each release.
