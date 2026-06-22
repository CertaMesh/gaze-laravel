# Conversational-loop guidance

This page expands the conversational-loop guidance from the [README](../../README.md). Use it when chat, tool-calling, or planner-executor flows need repeatable token handling across turns.

Multi-turn agents (chat UIs, tool-calling loops, planner-executor agents) do not get persistent tokens in the current adapter contract. Each turn produces its own blob. Two patterns work; pick one and stick with it per conversation.

**Pattern A — list of blobs, restore in reverse order on render.**

Each turn appends a `GazeSession` to a per-conversation list. When you render the final assistant message to the user, walk the list newest-to-oldest and restore the surface text against each blob in turn. This handles the case where a token minted in turn 1 reappears verbatim in turn 4's assistant message.

```php
$blobs = []; // ordered, newest-last; encrypted at rest if persisted
foreach ($turns as $turn) {
    $session = $gaze->clean($turn->userInput);
    $blobs[] = $session;
    $turn->modelResponse = $llm->complete($session->cleanText, history: $tokenizedHistory);
}

// On user-visible render of the final assistant message:
$rendered = end($turns)->modelResponse;
foreach (array_reverse($blobs) as $session) {
    $rendered = $gaze->restore($session, $rendered); // most-recent tokens first
}
```

**Pattern B — restore only the final user-visible message.**

Tool-call payloads, intermediate planner thoughts, and any token-shaped text that is fed back into the next LLM turn stay tokenized. You only call `restore()` on the assistant text that is about to render to the human. This keeps the model's context window token-clean and prevents PII from leaking into tool arguments.

**Sharp edges (read these):**

- **Never restore intermediate tool-call payloads to user-visible surfaces.** A tool that takes `customer_email` will get the token; restore inside the tool only if the tool itself is the trust boundary (e.g. it is the email send action). Otherwise restore on the way out, not on the way in.
- **Never `sanitize once, trust forever`.** Each new user input is a new clean call. Reusing an old blob across turns silently misses PII added later in the conversation.
- **Never reuse one session-id across independent conversations or tenants.** The session-id keys the pseudonym counter namespace, so pooling it across unrelated contexts makes their tokens **cross-conversation linkable** (`PERSON_1` in one chat == `PERSON_1` in another) — a GDPR Art. 4(5) pseudonymization failure (upstream #277/#275). One session-id per logical isolation boundary: per conversation, per tenant, per trust domain; never a shared/global id. Same class of mistake as `sanitize once, trust forever`. Full rule: [daemon § Session-id is a pseudonym-namespace boundary](./daemon.md#session-id-is-a-pseudonym-namespace-boundary).
- **Cross-turn token drift.** Token IDs are not guaranteed stable across separate `clean()` invocations. If turn 4 needs to reference an entity from turn 1, prefer Pattern A over manual ID stitching.

If your conversational shape needs persistent tokens (stable across turns, restorable from any later turn), that is upstream tracked work — open an issue describing the shape so adopter friction is captured.
