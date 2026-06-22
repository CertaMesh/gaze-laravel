# Explanation

Explanation pages are **understanding-oriented**: they discuss the design, the threat model,
and the reasoning behind how `gaze-laravel` wraps upstream `gaze`. Read these to build a mental
model. For step-by-step tasks see the [how-to guides](../how-to/index.md), and for exact keys
and tables see [reference](../reference/index.md).

- [Architecture](./architecture.md) — how the package exposes the upstream CLI contract as Laravel surfaces.
- [Blob lifecycle](./blob-lifecycle.md) — why session blobs are treated as sensitive, request-scoped values.
- [Enabling NER](./ner.md) — what named-entity recognition adds on top of regex and rulepack detection.
- [GDPR posture](./gdpr.md) — adopter guidance (not legal advice) mapping adapter surfaces to GDPR concepts; posture is signed upstream **and** encrypted at rest by the adapter.
- [Security model](./security.md) — what the adapter guarantees and what remains application or infrastructure responsibility.

← Back to [documentation index](../README.md).
