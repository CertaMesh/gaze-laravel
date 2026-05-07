# Security model

This page expands the security model from the [README](../README.md). It describes adapter guarantees and the parts that remain application or infrastructure responsibilities.

**What the adapter guarantees:**

- Session blobs are encrypted at rest using Laravel's `Encrypter` (AES-256-GCM by default, keyed on `GAZE_ENCRYPTION_KEY` or `APP_KEY`).
- Restore happens owner-side: the original text is never sent to the model — only `$session->cleanText` (pseudonymized) crosses the model boundary.
- The adapter never logs or stores raw PII; `GazeSession::cleanText` and `GazeSession::ciphertext` are separate fields precisely to prevent accidental exposure.

**What the adapter does not guarantee:**

- Encryption key management: rotate and protect `GAZE_ENCRYPTION_KEY` / `APP_KEY` using your own key management practices.
- Transport security: connections to the LLM provider are outside this adapter's scope.
- Audit DB access control: `GAZE_AUDIT_DB_PATH` points to a SQLite file — OS-level file permissions apply.
- GDPR, DSGVO, or HIPAA compliance: the adapter is designed to support pseudonymization per GDPR Art. 4(5) and related frameworks, but compliance depends on your full data processing context, not this library alone.
