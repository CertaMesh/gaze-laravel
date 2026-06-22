# GDPR posture (adopter guidance)

> **This is engineering guidance to help you reason about GDPR posture; it is
> not legal advice — consult your DPO/counsel.** It mirrors upstream `gaze`'s
> GDPR notes ([CertaMesh/gaze#334](https://github.com/CertaMesh/gaze)) at the
> Laravel-adapter altitude. Whether your processing is lawful depends on your
> full data-processing context, not on this library alone (see the
> [security model](./security.md) for the adapter's hard guarantee boundary).

This page builds a mental model of how the adapter's surfaces line up with
the GDPR concepts adopters most often have to reason about. It is
understanding-oriented: for exact keys and tables see
[reference](../reference/index.md); for step-by-step tasks see the
[how-to guides](../how-to/index.md).

## Storage posture: signed upstream AND encrypted at rest by the adapter

Upstream describes the session blob as a **signed** snapshot — integrity and
authenticity, so a tampered or wrong-version blob is rejected on `restore()`.
The Laravel adapter's posture is **stronger**: the blob is **signed upstream
AND encrypted at rest by the adapter**.

The adapter encrypts `$session->ciphertext` with Laravel's `Encrypter`
(**AES-256-GCM** by default, keyed on `GAZE_ENCRYPTION_KEY` or `APP_KEY`)
before it is ever serialized — so a queued job payload or any persisted blob
is confidential at rest, not merely signed. State the adapter posture as
*"signed upstream and encrypted at rest by the adapter,"* never as upstream's
bare signed snapshot.

See the [security model](./security.md) for the guarantee boundary and the
[blob lifecycle](./blob-lifecycle.md) for where the encrypted blob is allowed
to live between `clean()` and `restore()`.

## Adapter surfaces mapped to GDPR concepts

This mapping is a reasoning aid, not a compliance checklist.

| GDPR concept | Adapter surface | Notes |
|---|---|---|
| **Pseudonymization** (Art. 4(5)) | `Gaze::clean()` / `Gaze::restore()` tokenization | Real values are replaced with reversible tokens; the mapping lives only in the signed-and-encrypted blob, owner-side. |
| **Storage limitation** (Art. 5(1)(e)) | session TTL — `gaze.session_ttl_seconds` / `GAZE_SESSION_TTL` | Bounds how long a blob can round-trip; align any cache/store TTL to it ([blob lifecycle](./blob-lifecycle.md)). |
| **Right to erasure support** (Art. 17) | audit purge — `Gaze::audit()->purge()` | Removes audit rows on a time window; supports erasure workflows. The adapter never stores raw PII in audit rows to begin with. |
| **Data minimization across the model boundary** (Art. 5(1)(c)) | only `cleanText` crosses; restore is owner-side | The pseudonymized text is the only thing handed to the LLM; the original never leaves your process. |

These are adapter *surfaces*, not guarantees of lawful processing — the
[security model](./security.md) is explicit that GDPR/DSGVO/HIPAA compliance
depends on your full processing context.

## Re-linkability is the central pseudonymization risk

Pseudonymization under Art. 4(5) only holds if the pseudonyms cannot be
re-linked to a person across contexts that should stay isolated. The single
most important adopter-controlled lever here is the **session-id**.

The session-id keys the pseudonym counter namespace. Reusing one id across
independent conversations or tenants pools their counters, so the *same*
token maps to the *same* person across contexts that should be isolated —
their pseudonyms become **cross-conversation linkable**. That is precisely
an Art. 4(5) failure: data becomes re-linkable across contexts it was
supposed to be isolated from (upstream #277 / #275).

**Rule: one session-id per logical isolation boundary — per conversation,
per tenant, per trust domain; never a shared/global id across independent
contexts.** The full treatment, with derivation patterns, lives in
[daemon § Session-id is a pseudonym-namespace boundary](../how-to/daemon.md#session-id-is-a-pseudonym-namespace-boundary),
and the conversational-loop sharp edge is in
[conversational-loops](../how-to/conversational-loops.md).

## What stays the adopter's responsibility

The adapter cannot make your processing lawful. It pseudonymizes, encrypts
at rest, bounds storage by TTL, and supports erasure — but key management,
transport security to the model provider, audit-DB access control, lawful
basis, DPAs, and records of processing are yours. Consult your DPO/counsel;
this page is engineering guidance, not legal advice.

← Back to [explanation index](./index.md).
