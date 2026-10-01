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
 * FOUND, recorded before the pipeline acts (gaze v0.15.1
 * `Pipeline::clean_text_target`). They stay the same whether the safety-net
 * decision then tokenized the spans (`resolve`), replaced them with a one-way
 * `[REDACTED:<class>]` marker (`redact`, or the `redact` fallback), or left them
 * raw (`tolerant`). The report carries no per-suspect outcome (upstream never
 * built the `action_taken` field its v0.8 design proposed), so the adapter
 * attaches the decision it forwarded as {@see $actsOnSuspects}. Under an acting
 * decision, upstream's own "not acted on" signal is the `UnactionableSubword`
 * telemetry row ({@see $unactionableSubwordCount}).
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
     * @param  bool  $actsOnSuspects  Whether the clean ran under a safety-net
     *                                decision that acts on suspects: `resolve` with
     *                                the `redact` or `strict` fallback, or `redact`.
     *                                False (the default) means observe semantics —
     *                                `strict`, `tolerant`, `resolve` with the
     *                                `tolerant` fallback, or unknown — where a
     *                                flagged span may have shipped raw.
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
     * Build a LeakReport from the decoded `leak_report` object. Tolerates absent
     * or malformed fields (defaults to zero counts / empty suspects) so a shape
     * drift never turns a clean() into a hard failure.
     *
     * `$actsOnSuspects` is not part of the upstream JSON: `Gaze::clean()` passes
     * it from the `--safety-net-mode` / `--safety-net-fallback` it forwarded.
     * Leave it false when the decision is unknown — that keeps every flagged
     * span that is not a class mismatch counted as possibly raw.
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
     * - Acting decision (`resolve` + `redact`/`strict` fallback, `redact`):
     *   upstream tokenized or marker-replaced every actionable suspect, so only
     *   an `UnactionableSubword` row means raw bytes shipped.
     * - Observe decision (`strict`, `tolerant`, `resolve` + `tolerant`
     *   fallback, or unknown): every suspect that is not a `ClassMismatch` may
     *   be raw. A class mismatch is already covered by a token of another
     *   class; upstream's own boundary counts only uncovered and partial-bleed
     *   suspects as suspected leaks.
     *
     * One upstream path stays invisible here: after the `redact` fallback has
     * run, a final scan's finding that no stage may act on ships in the report
     * without a telemetry row. It reads amber, never green.
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
     * with a one-way `[REDACTED:<class>]` marker under `redact` or the `redact`
     * fallback. The trust state is then Unverified (amber), not Suspect: the
     * primary pipeline missed the spans, the net caught them.
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
