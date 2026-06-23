<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

final readonly class GazeSession
{
    /**
     * @param  list<Entry>  $entries
     * @param  ?LeakReport  $leakReport  Upstream verification signal, or null when
     *                                   the binary did not emit a leak_report.
     */
    public function __construct(
        public string $cleanText,
        public EncryptedBlob $ciphertext,
        public int $detections,
        public array $entries = [],
        public ?LeakReport $leakReport = null,
    ) {}

    /**
     * Trust state of this clean result, derived from the upstream leak_report.
     *
     * A null leak_report degrades to Unverified — never Verified. The detection
     * count alone cannot back a green claim, so without the upstream coverage
     * check we report amber rather than over-assert safety. This is the whole
     * point of the surface: a count is not a verification.
     */
    public function coverageState(): CoverageState
    {
        return $this->leakReport?->coverageState() ?? CoverageState::Unverified;
    }

    /**
     * Whether the upstream safety net actively flagged a span that may still
     * carry raw PII. False when no leak_report was emitted (nothing flagged).
     */
    public function hasSuspectedLeak(): bool
    {
        return $this->leakReport?->hasSuspectedLeak() ?? false;
    }
}
