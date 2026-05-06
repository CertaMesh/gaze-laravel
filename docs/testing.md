# Testing Guide

## Overview

Gaze ships a first-class fake that mirrors the `Queue::fake()` / `Mail::fake()` idiom from Laravel core. The fake intercepts calls to `clean`, `restore`, and `audit()->purge()` without spawning a binary process, records every call for assertion, and returns deterministic fixture data.

---

## `Gaze::fake()`

Call `Gaze::fake()` at the start of a test. It swaps the bound `Gaze` service for a `FakeGaze`, returns the fake, and enables all assertion helpers on the facade.

```php
use Naoray\GazeLaravel\Facades\Gaze;

it('redacts user input before forwarding to the LLM', function () {
    Gaze::fake();

    $this->post('/api/prompt', ['text' => 'My name is Alice, email test@example.invalid']);

    Gaze::assertCleaned('My name is Alice, email test@example.invalid');
});
```

### What the fake does

- `clean($text)` — records the call and returns a `GazeSession` with:
  - `cleanText`: the input with token-pattern substitutions applied (see "Token grammar" below).
  - `ciphertext`: a real `EncryptedBlob` wrapping a base64-encoded JSON payload of the original text — so `restore` round-trips correctly.
  - `detections`: always `1`.
- `restore($session, $text)` — records the call and decodes the ciphertext to return the original `text` passed to `clean`.
- `audit()` — returns a `FakeAuditService` that records purge calls without executing any process.

### What the fake does NOT do

- It does not run the `gaze` binary.
- It does not validate policy configuration.
- It does not write to or read from an audit DB.
- It does not enforce `max_bytes` or encoding pre-flights.

For pre-flight validation behaviour, test against a real binary in the `Integration/` suite.

### Token grammar

The fake applies the same token pattern the binary produces, so clean-text fixtures in tests match what the real binary would return. Recognised patterns:

| Input | Clean-text output |
|---|---|
| `<Email_1>` | `<Name_1>` |
| `<Name_1>` | `<Name_1>` |
| `<Location_1>` | `<Name_1>` |
| `<Custom:order_id_1>` | `<Custom:order_id_1>` |
| `email1@example.test` | `email1@example.test` |
| `name_1` | `name_1` |
| Anything else containing `Alice` | `Name_1` substituted |

---

## Assert Methods

All assertions are on the `Gaze` facade. They fail with a descriptive PHPUnit message if `Gaze::fake()` was not called first.

### `assertCleaned(?string $expectedText = null)`

Asserts that `clean()` was called at least once. When `$expectedText` is given, asserts it was called with that exact string.

```php
Gaze::assertCleaned(); // at least one call
Gaze::assertCleaned('Contact us@example.invalid'); // exact text
```

### `assertNothingCleaned()`

Asserts that `clean()` was never called.

```php
Gaze::assertNothingCleaned();
```

### `assertCleanCount(int $expected)`

Asserts the exact number of `clean()` calls.

```php
Gaze::assertCleanCount(3);
```

### `assertRestored(?string $expectedText = null)`

Asserts that `restore()` was called at least once. When `$expectedText` is given, asserts it was called with that exact `$text` argument.

```php
Gaze::assertRestored();
Gaze::assertRestored('<Name_1> asked a question');
```

### `assertRestoreCount(int $expected)`

Asserts the exact number of `restore()` calls.

```php
Gaze::assertRestoreCount(1);
```

### `assertAuditPurged(?CarbonInterface $before = null)`

Asserts that `audit()->purge()->...->execute()` was called at least once. When `$before` is given, asserts it was called with that Carbon timestamp (compared as UTC ISO 8601 Zulu).

```php
Gaze::assertAuditPurged();
Gaze::assertAuditPurged(now()->subDays(90));
```

### `assertAuditPurgeCount(int $expected)`

Asserts the exact number of audit purge calls.

```php
Gaze::assertAuditPurgeCount(1);
```

### `assertNothingAudited()`

Asserts that no audit verb was called.

```php
Gaze::assertNothingAudited();
```

---

## Custom Handlers

Pass closures to stub return values or simulate error conditions.

### `cleanHandler`

Override the return value of every `clean()` call:

```php
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

Gaze::fake(
    cleanHandler: fn (string $text): GazeSession => new GazeSession(
        cleanText: str_replace('alice@example.invalid', '<Email_1>', $text),
        ciphertext: EncryptedBlob::wrap(base64_encode(json_encode(['text' => $text]))),
        detections: 1,
    ),
);
```

### `restoreHandler`

Override the return value of every `restore()` call:

```php
Gaze::fake(
    restoreHandler: fn (GazeSession $session, string $text): string
        => str_replace('<Email_1>', 'alice@example.invalid', $text),
);
```

### `auditPurgeHandler`

Override the return value of every `audit()->purge()->...->execute()` or `->dryRun()` call:

```php
use Naoray\GazeLaravel\Audit\AuditPurgeResult;

Gaze::fake(
    auditPurgeHandler: fn (string $beforeIso, bool $dryRun): AuditPurgeResult
        => new AuditPurgeResult(rawOutput: '', count: $dryRun ? 42 : 42),
);
```

---

## `FakeQueryBuilder`

`FakeAuditService::query()` returns a `FakeQueryBuilder`. The fake query builder is a no-op builder — it does not execute any process. Use it when testing code that calls `Gaze::audit()->query()` and you need to assert that the query path was reached, or when you want to stub a result.

```php
// FakeQueryBuilder is returned automatically from Gaze::fake()
$fake = Gaze::fake();
$fake->audit()->query()->execute(); // no-op, returns []
```

---

## PII-Safe Fixtures

**Never use real email addresses, phone numbers, or names in test fixtures.** Use:

- **Emails:** `.invalid` TLD (RFC 2606 reserved, never routes) — e.g. `user@example.invalid`, `test+tag@corp.invalid`
- **US phone numbers:** NANPA test range +1-555-0100 through +1-555-0199 (reserved by North American Numbering Plan)
- **UK phone numbers:** Ofcom reserved range +44 7700 900000 through +44 7700 900999

```php
$session = Gaze::clean('Contact alice@example.invalid or call +1-555-0100.');
```

---

## Running the Test Suite

### Unit and feature tests (no binary required)

```bash
composer test
# or directly:
./vendor/bin/pest
```

Run a specific group:

```bash
./vendor/bin/pest --group=unit
./vendor/bin/pest tests/Feature/
```

### Integration tests (binary required)

Integration tests under `tests/Integration/` spawn the real `gaze` binary. They are skipped automatically when the binary is absent. To run them, ensure the binary is installed:

```bash
composer install          # Composer plugin installs vendor/bin/gaze
./vendor/bin/pest tests/Integration/
```

Or with a specific binary path:

```bash
GAZE_BINARY=/usr/local/bin/gaze ./vendor/bin/pest tests/Integration/
```

### Contract snapshot tests

Snapshot tests under `tests/Contract/` pin the binary's `--help` output and variant contract. They guard against unintentional binary API changes:

```bash
./vendor/bin/pest tests/Contract/
```

To update snapshots after an intentional binary change:

```bash
./vendor/bin/pest tests/Contract/ --update-snapshots
```

---

## Static Analysis

```bash
composer analyse
# or directly:
./vendor/bin/phpstan analyse
```

Configuration: `phpstan.neon.dist`.

---

## Code Style

```bash
composer format
# or directly:
./vendor/bin/pint
```

Check without modifying:

```bash
./vendor/bin/pint --test
```

Configuration: `pint.json`.
