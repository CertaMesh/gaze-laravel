<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * Typed projection of the upstream gaze `leak_report` — the verification signal
 * the clean pipeline emits alongside the redacted text.
 *
 * Mirrors the upstream `LeakReportResponse` shape (gaze v0.15.1,
 * crates/gaze-cli/src/pipeline/run.rs). The adapter previously dropped this
 * field entirely, leaving callers to infer safety from the detection count —
 * which over-asserts, since a high count never proves a span did not bleed
 * through. This DTO surfaces the upstream coverage check so callers can show an
 * honest trust state instead of a falsely-reassuring green.
 *
 * METADATA ONLY: every field is a count, a backend label, a flag, or a hash. No
 * source text and no byte offsets are carried (see {@see LeakSuspect}); the
 * `telemetry[]` rows are only counted, because `UnactionableSubword` rows carry
 * byte offsets.
 *
 * What the report records: `suspects[]` / `stats` are what the Pass-3 safety net
 * FOUND, not what is still raw. The first scan is recorded before the pipeline
 * acts (gaze v0.15.1 `Pipeline::clean_text_target`); it stays the same whether
 * the safety-net decision then tokenized the spans (`resolve`), replaced them
 * with a one-way `[REDACTED:<class>]` marker (`redact`), or left them raw
 * (`tolerant`). Under `resolve`, later scans append to the same list: what the
 * second resolve round and the `redact` fallback acted on, and — once that
 * fallback has run — what a final scan flagged, which upstream ships raw
 * (`Pipeline::apply_safety_net_policy`, `Pipeline::admit_terminal_output`). The
 * report carries no per-suspect outcome or round (upstream never built the
 * `action_taken` field its v0.8 design proposed), so the adapter attaches how
 * the report may be read as {@see $actsOnSuspects}. Under an acting decision,
 * upstream's own "not acted on" signal is the `UnactionableSubword` telemetry
 * row ({@see $unactionableSubwordCount}); the final scan after the `redact`
 * fallback emits none for a raw finding, so that case reads as observe.
 *
 * The suspects channel is populated only when a safety net runs. The stock
 * release binary ships the Nym net since gaze 0.15.0 (OPF still needs a
 * `safety-net-openai` build); without a net the suspects stay 0/empty. The four
 * coverage-gap counts are always present.
 *
 * @see CoverageState for the resolved trust state.
 * @see https://github.com/CertaMesh/gaze
 */
final readonly class LeakReport
{
    /**
     * @param  list<LeakSuspect>  $suspects
     * @param  bool  $actsOnSuspects  Whether every flagged span was protected
     *                                unless upstream says otherwise: the clean ran
     *                                under a safety-net decision that acts on
     *                                suspects (`resolve` with the `redact` or
     *                                `strict` fallback, or `redact`) and the
     *                                `redact` fallback did not run. False (the
     *                                default) means observe semantics — `strict`,
     *                                `tolerant`, `resolve` with the `tolerant`
     *                                fallback, a `redact` fallback run, or unknown
     *                                — where a flagged span may have shipped raw.
     * @param  int  $unactionableSubwordCount  Number of upstream
     *                                         `UnactionableSubword` telemetry rows:
     *                                         word-like suspects no stage acted on,
     *                                         so their bytes stayed raw. Upstream
     *                                         emits them only under an acting
     *                                         decision.
     */
    public function __construct(
        public int $suspectCount,
        public int $uncoveredCount,
        public int $partialBleedCount,
        public int $classMismatchCount,
        public int $localeSkippedCount,
        public array $suspects = [],
        public ?string $replayHash = null,
        public bool $actsOnSuspects = false,
        public int $unactionableSubwordCount = 0,
    ) {}

    /**
     * Restore a serialized report, including one an older release serialized.
     *
     * A `GazeSession` may travel in a queued job's payload
     * (docs/explanation/blob-lifecycle.md), so a worker on this release can
     * unserialize a report written by v0.15.x, before `actsOnSuspects` and
     * `unactionableSubwordCount` existed. Unserialize never runs the
     * constructor, so its parameter defaults never apply: without this method
     * those properties stayed uninitialized and the first `coverageState()`
     * threw. A missing field takes its constructor default — for
     * `actsOnSuspects` that is false, observe semantics, which reads a flagged
     * span red exactly as v0.15.x did. The five counts have no default and stay
     * mandatory: a payload without them throws instead of reading green.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $data += ['suspects' => [], 'replayHash' => null, 'actsOnSuspects' => false, 'unactionableSubwordCount' => 0];

        $this->suspectCount = self::serializedInt($data, 'suspectCount');
        $this->uncoveredCount = self::serializedInt($data, 'uncoveredCount');
        $this->partialBleedCount = self::serializedInt($data, 'partialBleedCount');
        $this->classMismatchCount = self::serializedInt($data, 'classMismatchCount');
        $this->localeSkippedCount = self::serializedInt($data, 'localeSkippedCount');
        $this->unactionableSubwordCount = self::serializedInt($data, 'unactionableSubwordCount');
        $this->suspects = self::serializedSuspects($data['suspects']);

        $replayHash = $data['replayHash'];
        $actsOnSuspects = $data['actsOnSuspects'];
        if (! is_bool($actsOnSuspects) || ($replayHash !== null && ! is_string($replayHash))) {
            throw new \UnexpectedValueException('LeakReport payload carries an invalid actsOnSuspects or replayHash.');
        }

        $this->replayHash = $replayHash;
        $this->actsOnSuspects = $actsOnSuspects;
    }

    /**
     * Build a LeakReport from the decoded `leak_report` object. Tolerates absent
     * or malformed fields (defaults to zero counts / empty suspects) so a shape
     * drift never turns a clean() into a hard failure.
     *
     * `$actsOnSuspects` is not part of the upstream JSON: `Gaze::clean()` passes
     * it from the `--safety-net-mode` / `--safety-net-fallback` it forwarded,
     * and passes false for `resolve` + `redact` when the clean text carries a
     * `[REDACTED:` marker (the fallback ran). Leave it false when the decision
     * is unknown — that keeps every flagged span that is not a class mismatch
     * counted as possibly raw.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload, bool $actsOnSuspects = false): self
    {
        $stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];

        $replayHash = isset($payload['replay_hash']) && is_string($payload['replay_hash'])
            ? $payload['replay_hash']
            : null;

        return new self(
            suspectCount: self::count($stats, 'suspect_count'),
            uncoveredCount: self::count($stats, 'uncovered_count'),
            partialBleedCount: self::count($stats, 'partial_bleed_count'),
            classMismatchCount: self::count($stats, 'class_mismatch_count'),
            localeSkippedCount: self::count($stats, 'locale_skipped_count'),
            suspects: self::mapSuspects($payload['suspects'] ?? null),
            replayHash: $replayHash,
            actsOnSuspects: $actsOnSuspects,
            unactionableSubwordCount: self::countTelemetry($payload['telemetry'] ?? null, 'UnactionableSubword'),
        );
    }

    /**
     * Resolve the trust state. Suspect (red) wins over Unverified (amber) when
     * both a possibly-raw suspect and coverage gaps are present — the harder
     * signal dominates. Suspects the pipeline protected are amber, never green:
     * upstream's "no leaks" contract is exit 0 with `suspect_count = 0`.
     * Verified (green) requires no suspects AND no gaps.
     */
    public function coverageState(): CoverageState
    {
        if ($this->hasSuspectedLeak()) {
            return CoverageState::Suspect;
        }

        if ($this->suspectCount > 0 || $this->hasCoverageGap()) {
            return CoverageState::Unverified;
        }

        return CoverageState::Verified;
    }

    /**
     * Whether a span the safety net flagged may still be raw in the clean text.
     *
     * - Acting ({@see $actsOnSuspects}: `resolve` + `redact`/`strict` fallback,
     *   `redact`, and the `redact` fallback did not run): upstream tokenized or
     *   marker-replaced every actionable suspect, so only an
     *   `UnactionableSubword` row means raw bytes shipped.
     * - Observe (`strict`, `tolerant`, `resolve` + `tolerant` fallback, a
     *   `redact` fallback run, or unknown): every suspect that is not a
     *   `ClassMismatch` may be raw. A class mismatch is fully covered by a
     *   replacement of another class; upstream's own boundary counts only
     *   uncovered and partial-bleed suspects as suspected leaks.
     *
     * The `redact` fallback run reads as observe because upstream scans the
     * output once more afterwards and ships what that scan flags raw, in this
     * report and with no telemetry row (`TerminalAdmission::Admit`). The report
     * cannot say which suspect that was, so a fallback run is red whenever it
     * holds a suspect that is not a class mismatch — also when every span was
     * in fact protected.
     *
     * Distinct from coverage gaps, which are weaker "could not fully verify"
     * signals rather than a possibly-raw span.
     */
    public function hasSuspectedLeak(): bool
    {
        if ($this->actsOnSuspects) {
            return $this->unactionableSubwordCount > 0;
        }

        return $this->suspectCount > $this->classMismatchCount;
    }

    /**
     * Whether the safety net flagged at least one span and the safety-net
     * decision protected all of them: tokenized under `resolve`, or replaced
     * with a one-way `[REDACTED:<class>]` marker under `redact`. The trust state
     * is then Unverified (amber), not Suspect: the primary pipeline missed the
     * spans, the net caught them. Never true once the `resolve` decision's
     * `redact` fallback has run (see {@see hasSuspectedLeak()}).
     */
    public function hasResolvedSuspects(): bool
    {
        return $this->actsOnSuspects
            && $this->suspectCount > 0
            && ! $this->hasSuspectedLeak();
    }

    /**
     * Whether upstream reported any partial-coverage signal: an uncovered span,
     * a partial bleed, a class mismatch, or a locale-skipped field.
     */
    public function hasCoverageGap(): bool
    {
        return $this->uncoveredCount > 0
            || $this->partialBleedCount > 0
            || $this->classMismatchCount > 0
            || $this->localeSkippedCount > 0;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private static function count(array $stats, string $key): int
    {
        return isset($stats[$key]) && is_numeric($stats[$key]) ? (int) $stats[$key] : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function serializedInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new \UnexpectedValueException("LeakReport payload is missing a valid {$key}.");
        }

        return $value;
    }

    /**
     * @return list<LeakSuspect>
     */
    private static function serializedSuspects(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new \UnexpectedValueException('LeakReport payload carries invalid suspects.');
        }

        $suspects = [];
        foreach ($raw as $suspect) {
            if (! $suspect instanceof LeakSuspect) {
                throw new \UnexpectedValueException('LeakReport payload carries invalid suspects.');
            }

            $suspects[] = $suspect;
        }

        return $suspects;
    }

    /**
     * Count the `telemetry[]` rows of one `kind`. Only the count survives: the
     * rows themselves (offsets included) are never carried.
     */
    private static function countTelemetry(mixed $raw, string $kind): int
    {
        if (! is_array($raw)) {
            return 0;
        }

        $count = 0;
        foreach ($raw as $row) {
            if (is_array($row) && ($row['kind'] ?? null) === $kind) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<LeakSuspect>
     */
    private static function mapSuspects(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $suspects = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $suspects[] = LeakSuspect::fromArray($item);
            }
        }

        return $suspects;
    }
}
