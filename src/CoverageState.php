<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * Trust state of a clean() result, derived from the upstream `leak_report`.
 *
 * This is a verification signal, NOT a detection count. A high detection count
 * never implies safety on its own — NER can fire many times while a real PII
 * span bleeds through uncovered. CoverageState answers the honest question
 * "did upstream's coverage check pass?" rather than "how many tokens fired?".
 *
 *  - Verified   (green):  no suspects AND no coverage gaps reported.
 *  - Unverified (amber):  nothing flagged may still be raw, but coverage is
 *                         partial — one or more spans were uncovered, partially
 *                         bled, classified under the wrong class, or skipped for
 *                         an absent locale — or the safety net flagged spans and
 *                         the `resolve` / `redact` decision protected them.
 *  - Suspect    (red):    a span the safety net flagged may still carry raw PII
 *                         in the clean text (e.g. under `tolerant`, or an
 *                         upstream `UnactionableSubword` the pipeline left raw).
 *
 * @see LeakReport for how each state is computed from the upstream counts.
 */
enum CoverageState: string
{
    case Verified = 'verified';
    case Unverified = 'unverified';
    case Suspect = 'suspect';
}
