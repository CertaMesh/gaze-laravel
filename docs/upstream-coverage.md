# Upstream Coverage

Living parity checklist for upstream `EmpireTwo/gaze` v0.7.2.

## Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze clean` | `Naoray\GazeLaravel\Gaze::clean()` |
| `gaze restore` | `Naoray\GazeLaravel\Gaze::restore()` |
| `gaze audit query` | `Gaze::audit()->query()` |
| `gaze audit purge` | `Gaze::audit()->purge()` |

## Clean Flags

| Upstream flag | Laravel surface |
|---|---|
| `--policy` | `gaze.policy_path` / `GAZE_POLICY_PATH` |
| `--format=json` | Always set by `Gaze::clean()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--session-ttl` | `gaze.session_ttl_seconds` / `GAZE_SESSION_TTL` |
| `--session-scope` | `gaze.session_scope` / `GAZE_SESSION_SCOPE` |
| `--audit-db` | `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH` |
| `--locale` | `gaze.locale` / `GAZE_LOCALE` |
| `--rulepack-bundled` | `gaze.rulepacks` / `GAZE_RULEPACKS` |
| `--rulepack-path` | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` |
| `--safety-net` | `gaze.safety_net` / `GAZE_SAFETY_NET` |
| `--openai-filter-device` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` |
| `--openai-filter-command` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` |
| `--openai-filter-checkpoint` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` |
| `--openai-filter-operating-point` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` |
| `--safety-net-timeout-ms` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` |
| `--safety-net-input-limit-bytes` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` |
| `--safety-net-mode` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` |

## Restore Flags

| Upstream flag | Laravel surface |
|---|---|
| `--format=json` | Always set by `Gaze::restore()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--restore-mode` | `gaze.restore_mode` / `GAZE_RESTORE_MODE` |

## Exception Variants

| Upstream variant | Laravel exception |
|---|---|
| `StdinParse` | `GazeStdinParseException` |
| `EmptyInput` | `GazeEmptyInputException` |
| `InputTooLarge` | `GazeInputTooLargeException` |
| `InvalidEncoding` | `GazeInvalidEncodingException` |
| `PolicyConfig` | `GazePolicyConfigException` or `GazePolicyConfigDetailException` when `detail` exists |
| `SafetyNetConfig` | `GazeSafetyNetConfigException` |
| `SafetyNet` | `GazeSafetyNetFailureException` |
| `AuditPurgeIso8601` | `GazeAuditPurgeIso8601Exception` |
| `UnknownToken` | `GazeUnknownTokenException` |
| `UnsupportedSessionScope` | `GazeUnsupportedSessionScopeException` |
| `InvalidSignature` | `GazeInvalidSignatureException` |
| `InvalidBlobVersion` | `GazeInvalidBlobVersionException` |
| `BlobExpired` | `GazeBlobExpiredException` |
| `Pipeline` | `GazePipelineException` |
| `Io` | `GazeIoException` |
| `SigPipe` | `GazeSigPipeException` |
| `PolicyOpen` | `GazePolicyOpenException` |

## Deferred

| Upstream surface | Reason |
|---|---|
| `--context-json` | P1 design item; needs PHP API design before exposure. |
