# Security model

This page expands the security model from the [README](../../README.md). It describes adapter guarantees and the parts that remain application or infrastructure responsibilities.

**What the adapter guarantees:**

- Session blobs are encrypted at rest using Laravel's `Encrypter` (AES-256-GCM by default, keyed on `GAZE_ENCRYPTION_KEY` or `APP_KEY`).
- Restore happens owner-side: the original text is never sent to the model — only `$session->cleanText` (pseudonymized) crosses the model boundary.
- The adapter never logs or stores raw PII; `GazeSession::cleanText` and `GazeSession::ciphertext` are separate fields precisely to prevent accidental exposure.

**What the adapter does not guarantee:**

- Encryption key management: rotate and protect `GAZE_ENCRYPTION_KEY` / `APP_KEY` using your own key management practices.
- Transport security: connections to the LLM provider are outside this adapter's scope.
- Audit DB access control: `GAZE_AUDIT_DB_PATH` points to a SQLite file — OS-level file permissions apply.
- GDPR, DSGVO, or HIPAA compliance: the adapter is designed to support pseudonymization per GDPR Art. 4(5) and related frameworks, but compliance depends on your full data processing context, not this library alone.

## Trust state: a count is not a verification

`Gaze::clean()` returns a `GazeSession` carrying a detection count
(`$session->detections`). It is tempting to treat a non-zero count as proof of
safety — *"cleaned, 7 detections"* reads like a green checkmark. It is not one.
A detection count says how many spans the recognizers **fired on**; it says
nothing about whether a real PII value **bled through uncovered**. NER can fire
seven times and still miss the eighth value, or cover a span under the wrong
class. A count that goes up is not a count of things made safe.

Upstream already computes the honest answer — its `leak_report` records whether
the redaction actually covered what it found. The adapter surfaces that as a
trust state rather than letting callers reverse-engineer safety from a number:

- **`$session->coverageState()`** returns a `CoverageState` — `Verified` (green),
  `Unverified` (amber), or `Suspect` (red).
- **`$session->hasSuspectedLeak()`** is `true` only when upstream's observer-only
  safety net actively flagged a span that may still carry raw PII.

The resolution is deliberately conservative:

- **`Verified`** requires *both* no suspects *and* no coverage gaps — it reflects
  an upstream verification, not a detection tally.
- **`Unverified`** is the default whenever coverage is partial **or** there is no
  `leak_report` to back a green at all. Absence of evidence is treated as
  *unverified*, never as *verified*. Show amber, not green.
- **`Suspect`** wins over everything when the safety net flags a possible leak.

Drive your UI and gating off `coverageState()` / `hasSuspectedLeak()`, not off
`detections`. The `LeakReport` is metadata only — it never carries source text or
byte offsets — so it is safe to log, serialise, or surface to operators. See the
[upstream-coverage reference](../reference/upstream-coverage.md#clean-leak-report--trust-state-v011x)
for the field-level shape and the stock-binary caveat (the red `Suspect` state
requires a safety-net-enabled build).
