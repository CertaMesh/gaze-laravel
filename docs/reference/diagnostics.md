# Latency baseline / Diagnostic

This page expands the latency diagnostics guidance from the [README](../../README.md). Use it to establish a cold-start baseline before comparing environments or reporting performance issues.

`Gaze::clean()` currently invokes the upstream `gaze clean` command as a one-shot subprocess for every call. With NER enabled, every invocation loads the NER model from disk before it can return a response. This is the current CLI contract, so repeated calls are not a warm-up run: every `gaze:bench --requests=N` sample pays the full model-load cost.

Use `gaze:bench` to measure your own cold baseline:

```bash
php artisan gaze:bench --requests=10
php artisan gaze:bench --requests=10 --json
```

JSON output includes `bench_schema_version`, `mode: "cold"`, `first_ms`, percentile fields, chronological `samples_ms`, and a small environment fingerprint. For `--requests >= 1000`, samples default to `head` mode (first 100 plus last 100); use `--samples=full` or `--samples=none` when you need a different payload size.

Daemon mode is tracked upstream. Once it ships, this package will gain warm worker-pool support in a follow-up release. Until then, this command is diagnostic only: it establishes a cold-start baseline you can compare across machines, releases, or issue reports.

## Upstream policy warnings in `gaze:doctor`

gaze 0.15 and later print policy warnings on stderr, but only when a clean **succeeds**. `Gaze::clean()` discards stderr on success, so these warnings never reach your app or its logs. `php artisan gaze:doctor` runs one real `gaze clean` to show them (#159):

- **Input:** the fixed, PII-free string `gaze doctor probe`.
- **Argv:** the same binary, policy, pre-flight guards and pipeline flags `Gaze::clean()` uses (locale, rulepacks, NER threshold, safety net, session scope). The probe leaves out `--audit-db`, so doctor writes no audit row. It uses `gaze.timeout_seconds` as its timeout.
- **Output:** each stderr line that starts with `warning:` or `notice:` becomes a WARN line under an `upstream warnings` row. Nothing else from stderr is printed. gaze builds these lines from class, family and rulepack names, never from the input.
- **Exit code:** the warnings leave it unchanged. When the probe itself fails, doctor prints the typed error message (stage `clean probe`) and runs its static policy checks:
  - a NonRetryable failure (a broken policy, a missing safety-net model; see [the retry contract](exceptions.md#retry-contract-interfaces)) is a red `FAIL` row and exit `1`, because every `Gaze::clean()` fails the same way;
  - a transient failure or a timeout is a yellow `probe failed` row, and the exit code stays unchanged.
- **Skipped:** with a policy-level `[session] scope = "ephemeral"` and no `GAZE_SESSION_SCOPE` override, `Gaze::clean()` refuses before spawning, so there is nothing to probe. Doctor already warns about that scope (the daemon handles it), shows `skipped` here and runs the static checks only.

What gaze 0.15.1 reports:

| Line | Meaning | Fix |
|---|---|---|
| `warning: policy preserves N detected classes without a reachable class rule: …` | Those classes reach the model raw (upstream #641). | Set the policy's default rule to `action = "tokenize"`, or add a `[[rule]]` per class. Doctor prints this hint under the line. |
| `warning: class generalize is one-way (no restore token) for: …` | `restore()` cannot bring those values back. | Use `tokenize` if you need a round-trip. |
| `notice: core rulepack floor is off` | The effective rulepack list has no `core`, so emails, phones, IBANs, cards and IPs reach the model raw. | Keep `core` in `GAZE_RULEPACKS` and in `[policy.rulepacks] bundled`. |
| `warning: policy names a member class of 'custom:family:…'` | A collision family has no reachable rule of its own (upstream #360). | Add the `[[rule]]` the line names, before your default rule. |
| `notice: command line disabled policy safety net nym` | `GAZE_SAFETY_NET` replaces the policy's Nym net with another backend. | Set `GAZE_SAFETY_NET_BACKEND=nym`, or unset `GAZE_SAFETY_NET` to keep the policy's net. |

**Overlap with the static checks.** Doctor also parses the policy itself: preserve default, missing `core`, deprecated `core-extended`. gaze is the source of truth, so doctor prints each finding once:

- On gaze 0.15 or later, after a successful probe, gaze's lines replace the static preserve-default and missing-`core` checks. A silent probe means gaze found nothing to warn about, even when the static parse would have warned. For example, a `preserve` default is harmless when every class has its own rule.
- On older binaries, or when the probe fails, the static checks run. A matching gaze line still replaces its static check.
- The static `core-extended` check always runs, because it also covers the policy file and gaze does not. gaze's own `core-extended` line is dropped as a duplicate.

**Scope.** The probe covers `gaze.policy_path`. A separate `gaze.daemon.policy_path` is not probed; the daemon writes the same lines to `gaze.daemon.stderr_path`.

**Why `Gaze::clean()` does not log these lines.** The adapter never logs raw gaze stderr. Failures log only its SHA-256 and a typed variant. Today's warning lines carry no input text, but `clean()` runs on real user input, and a passthrough logger would forward any future upstream line unvetted. The warnings also depend on the policy, not the request, so logging them per call would repeat the same lines on every request and job. Run `gaze:doctor` after each policy or binary change instead.
