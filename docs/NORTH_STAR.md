# gaze-laravel — North Star

The project compass. Decision tie-breaker when implementation choices fork. Auto-injected into delegate briefs by the orchestrator-mode dispatch.

## Mission

`gaze-laravel` is the **PHP gate of [`gaze`](https://github.com/CertaMesh/gaze)**. It exposes upstream pseudonymization, audit, proxy, and safety-net capabilities through idiomatic Laravel surfaces — Facade methods, artisan commands, config keys, typed exceptions, queue contracts. Detection, NER, validators, and policy semantics live in the Rust crate; this package never re-implements them. The package's job is to make the upstream CLI contract feel native to a Laravel adopter so PII / PHI / secret pseudonymization for LLM and agent pipelines is one `Gaze::clean()` / `Gaze::restore()` call away.

## Target users

- **Primary — Laravel adopters** building LLM- or agent-backed features that must not leak PII / PHI / secrets across the model boundary. They want a Facade, a config file, and typed exceptions — not a Rust binary to babysit. They evaluate this package on adopter ergonomics, reliability, and the strength of the encrypted-blob trust contract.
- **Secondary — contributors** maintaining parity with upstream gaze. They work the surface-promotion rule on every gaze release: scan new flags, classify as wrap / passthrough / defer, and keep `docs/reference/upstream-coverage.md` honest.
- **Not a user** — anyone wanting in-process PHP pseudonymization without the gaze binary. That's a non-goal; see below.

## Principles (decision tie-breakers)

1. **Detection stays upstream.** Never re-implement pseudonymization, NER, validators, or policy semantics in PHP. If a behaviour needs net-new detection logic, the work happens in the Rust repo first; the adapter wraps the resulting flag.
2. **Adopter axes** — when surfaces compete, weight by:
   - **Reliability** — the wrapper never crashes worse than the binary; subprocess failures classify into typed exit buckets.
   - **Reversibility** — clean/restore round-trip via the signed encrypted session blob is the trust contract; nothing breaks it.
   - **Agentic-first** — pipelines run in queues, long-lived sessions, multi-turn LLM loops; the surface must work outside a single HTTP request.
   - **Trust** — session blobs are encrypted at rest; tokens never leak into logs, Livewire wire state, or audit rows.
   - **Adopter ergonomics** — facade > artisan > config > env; null config = upstream default; one CLI invocation = one Facade call.
3. **Surface promotion rule.** An upstream feature gets a Laravel surface only when (a) it expresses naturally as a Laravel idiom (facade method, artisan command, config key, env var, typed exception) AND (b) at least one adopter would actually call it from PHP. Otherwise expose via raw argv passthrough, document in `docs/reference/upstream-coverage.md` deferred table, or skip.
4. **Reversibility is sacrosanct.** The signed-session clean/restore round-trip is the package's core promise. Performance optimizations, convenience shortcuts, and ergonomic sugar never erode it. If a change would break round-trip determinism, the change does not ship.
5. **One CLI invocation = one Facade call.** Don't smuggle multi-step orchestration into a single method. Multi-step flows compose at the adopter level using the Facade primitives.
6. **Null config means upstream default.** Every config key that forwards a flag defaults to `null` and lets the binary decide. The adapter does not pin opinions the binary doesn't pin. Adopter opt-in is explicit.
7. **Doctor before failure.** When upstream introduces a new pinned-artifact or feature-flag requirement, surface it through `php artisan gaze:doctor` so adopters discover the gap before runtime.

## Non-goals

- **Re-implementing detection, NER, validators, or safety-net logic in PHP.** The Rust crate is the source of truth.
- **Wrapping non-Laravel-idiom surfaces.** Raw HTTP servers, MCP server lifecycle, document-OCR pipelines, and other Rust-native runtimes don't get faked PHP surfaces. They live in upstream or in sibling packages.
- **Supporting unmaintained Laravel versions.** Current support: PHP `^8.2`, Laravel `^11.0 || ^12.0 || ^13.0`.
- **Supporting unmaintained PHP versions** when upstream Laravel drops them.
- **Owning binary distribution.** Pre-built binaries cover Linux x86_64 and macOS arm64; adopters on other targets build from source and set `GAZE_BINARY`. The adapter is a wrapper, not a packager.
- **Becoming a generic CLI wrapper.** This package is specific to `gaze`. Don't add subprocess abstractions that pretend otherwise.
- **Persisting plaintext session state anywhere.** Session blobs are encrypted at rest; restore is owner-side; tokens never cross the model boundary in clear.

## SemVer policy (pre-1.0)

Pre-1.0, so the MAJOR slot stays at `0`. The package follows this convention, anchored in `docs/how-to/upgrading.md` and the v0.9.0 release framing:

- **MINOR** — any net-new adopter-facing surface: new Facade method, new artisan command, new config key, new typed exception class, new enum case, new doctor probe. Also: upstream binary pin bumps that forward a new flag, change a default, or alter a wire shape adopters can observe.
- **PATCH** — behaviour bug fixes that change no surface, documentation-only patches, internal refactors with no API change. Binary pin bumps to a same-feature upstream patch may ship as PATCH when the adapter exposes no new flag.

When in doubt, prefer MINOR. The v0.8.2 → v0.9.0 reframing (patch → minor for the Kiji safety-net adopter surface) is the precedent: net-new adopter knobs are MINOR even when the upstream change feels small.

Post-1.0 the package will switch to standard SemVer; the convention above will collapse into MINOR/PATCH under a stable MAJOR.

## How this file is used

- **Auto-injected** into every orchestrator delegate brief. Workers read it before touching the codebase so principle 1–7 break ties without re-explanation.
- **Updated** only via PR review by the maintainer. Drift between this file and the working code is a bug — fix the file or fix the code.
- **Cross-referenced** from `AGENTS.md` and `docs/reference/upstream-coverage.md`. Living-roadmap and coverage matrices state what is exposed; this file states why.
- **Not a treatise.** Keep it under ~150 lines. Add a principle only when a real decision can't be made without it; remove one when no decision has cited it in two releases.
