# Blob lifecycle

This page expands the blob lifecycle guidance from the [README](../../README.md). Treat session blobs as sensitive, request-scoped values unless you have a documented threat model.

The session blob (`$session->ciphertext`) is the only thing that lets `restore()` reverse the tokens. Treat it as sensitive: anyone who can read both the blob and a clean LLM response can derive the original PII.

**Where the blob lives between `clean()` and `restore()`:**

- **Default — same request, method scope.** Sync HTTP requests should call `clean()` and `restore()` in the same controller/service method. The `GazeSession` lives on the stack and is GC'd when the request ends.
- **Permitted — encrypted job payload.** Dispatching a queued job that carries the `GazeSession` is fine: the blob is already AES-encrypted under your `GAZE_ENCRYPTION_KEY` (or `APP_KEY`) before serialization. Combine with the Telescope/Pulse exclusion below so the encrypted payload does not get re-logged in plaintext-adjacent telemetry.
- **NOT permitted — cross-request persistence without a threat model.** Do not store `$session->ciphertext` in the user session, request cache, durable cache (Redis/Memcached without TTL alignment), or your application database. Doing so widens the blast radius of any cache/DB compromise from "ciphertext only" to "ciphertext plus the matching clean text it was generated against".

**Threat model:**

- The blob is **request-scoped by default**. It is never serialized to the Laravel session store, the framework cache, or the database by this package.
- The blob is **never written to logs**. Adapter exceptions deliberately omit ciphertext in their `getMessage()` and context arrays.
- The blob is **never shared across tenant boundaries**. If your app is multi-tenant, scope the queue connection and audit DB per tenant; the package does not enforce tenant isolation on your behalf.
- Any persistence layer you add (e.g. resumable wizards, draft autosave) must document why the extended lifetime is safe in your environment and align the cache TTL with `GAZE_SESSION_TTL`.

If you find yourself needing to round-trip a blob through a long-lived store, that is a strong signal you want the upstream persistent-token mode (tracked upstream); raise an issue rather than rolling your own cross-request persistence.
