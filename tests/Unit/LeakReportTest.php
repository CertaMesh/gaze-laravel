<?php

declare(strict_types=1);

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\LeakReport;
use CertaMesh\Gaze\LeakSuspect;

/**
 * Representative upstream `leak_report` shapes. Mirrors gaze v0.15.1
 * `LeakReportResponse` (crates/gaze-cli/src/pipeline/run.rs):
 *   { stats: {suspect_count, uncovered_count, partial_bleed_count,
 *             class_mismatch_count, locale_skipped_count},
 *     suspects: [LeakSuspectResponse...], telemetry: [...], replay_hash? }
 *
 * @param  array<string, int>  $stats
 * @param  list<array<string, mixed>>  $suspects
 * @return array<string, mixed>
 */
function leakReportArray(array $stats = [], array $suspects = [], ?string $replayHash = null): array
{
    $report = [
        'stats' => array_merge([
            'suspect_count' => 0,
            'uncovered_count' => 0,
            'partial_bleed_count' => 0,
            'class_mismatch_count' => 0,
            'locale_skipped_count' => 0,
        ], $stats),
        'suspects' => $suspects,
        'telemetry' => [],
    ];

    if ($replayHash !== null) {
        $report['replay_hash'] = $replayHash;
    }

    return $report;
}

/**
 * The `leak_report` the real gaze 0.15.1 release binary emits with the Nym net
 * for the synthetic input `Invoice date 1971-05-30, plate B-MW 1234` (stock
 * policy, `--safety-net=nym`). Captured byte-for-byte; the report is IDENTICAL
 * under every mode that ships output:
 *
 *   resolve (default) / resolve + strict fallback / resolve + tolerant fallback
 *       clean_text "Invoice date <h:Custom:date_1>, plate <h:Custom:license_plate_1>"
 *   redact
 *       clean_text "Invoice date [REDACTED:custom:date], plate [REDACTED:custom:license-plate]"
 *   tolerant (GAZE_ALLOW_TOLERANT=1)
 *       clean_text "Invoice date 1971-05-30, plate B-MW 1234"   <- raw
 *   strict
 *       exit 3, {"error":"SafetyNet","exit":3,"variant":"SuspectedLeak"}, no report
 *
 * So the report alone cannot say whether the suspects are still raw; the
 * decision (`actsOnSuspects`) has to come from the forwarded flags.
 *
 * @return array<string, mixed>
 */
function nymLeakReport0151(): array
{
    return json_decode(<<<'JSON'
{"stats":{"suspect_count":2,"uncovered_count":2,"partial_bleed_count":0,"class_mismatch_count":0,"locale_skipped_count":0},"suspects":[{"safety_net_id":"nym-small-int8","raw_label":"DATE_OF_BIRTH>=0.9","mapped_class":"Custom:date","leak_kind":"uncovered","span_len":10,"score":0.9992361},{"safety_net_id":"nym-small-int8","raw_label":"LICENSE_PLATE>=0.5","mapped_class":"Custom:license_plate","leak_kind":"uncovered","span_len":9,"score":0.99993986}],"telemetry":[]}
JSON, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Upstream `LeakTelemetryResponse::UnactionableSubword` (gaze 0.15.1
 * crates/gaze-cli/src/pipeline/run.rs, `#[serde(tag = "kind")]`): a word-like
 * suspect that starts or ends inside a word. No stage acts on it under
 * `resolve` / `redact`, so its bytes ship raw. Nym cannot produce one (its
 * classes are all `custom:*`, which the sub-word guard exempts), so this shape
 * is built from the upstream serializer, with an OPF-style suspect.
 *
 * @return array<string, mixed>
 */
function unactionableSubwordReport(): array
{
    return [
        'stats' => [
            'suspect_count' => 1,
            'uncovered_count' => 1,
            'partial_bleed_count' => 0,
            'class_mismatch_count' => 0,
            'locale_skipped_count' => 0,
        ],
        'suspects' => [[
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'raw_label' => 'private_person',
            'mapped_class' => 'Name',
            'leak_kind' => 'uncovered',
            'span_len' => 5,
            'score' => 0.81,
        ]],
        'telemetry' => [[
            'kind' => 'UnactionableSubword',
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'class' => 'name',
            'start' => 8,
            'end' => 13,
            'document_kind' => 'text',
        ]],
    ];
}

it('reads the captured Nym report as protected under an acting decision (resolve / redact)', function () {
    $report = LeakReport::fromArray(nymLeakReport0151(), actsOnSuspects: true);

    expect($report->suspectCount)->toBe(2)
        ->and($report->uncoveredCount)->toBe(2)
        ->and($report->actsOnSuspects)->toBeTrue()
        ->and($report->unactionableSubwordCount)->toBe(0)
        ->and($report->hasSuspectedLeak())->toBeFalse()
        ->and($report->hasResolvedSuspects())->toBeTrue()
        // Protected suspects are amber, never green: the primary pass missed them.
        ->and($report->coverageState())->toBe(CoverageState::Unverified);
});

it('reads the same captured Nym report as Suspect under an observe decision (tolerant)', function () {
    $report = LeakReport::fromArray(nymLeakReport0151(), actsOnSuspects: false);

    expect($report->hasSuspectedLeak())->toBeTrue()
        ->and($report->hasResolvedSuspects())->toBeFalse()
        ->and($report->coverageState())->toBe(CoverageState::Suspect);
});

it('keeps observe semantics when the decision is unknown (fromArray without a decision)', function () {
    $report = LeakReport::fromArray(nymLeakReport0151());

    expect($report->actsOnSuspects)->toBeFalse()
        ->and($report->coverageState())->toBe(CoverageState::Suspect);
});

it('reports Suspect for an UnactionableSubword row even under an acting decision', function () {
    $report = LeakReport::fromArray(unactionableSubwordReport(), actsOnSuspects: true);

    expect($report->unactionableSubwordCount)->toBe(1)
        ->and($report->hasSuspectedLeak())->toBeTrue()
        ->and($report->hasResolvedSuspects())->toBeFalse()
        ->and($report->coverageState())->toBe(CoverageState::Suspect);
});

it('carries only the UnactionableSubword count — never the telemetry offsets', function () {
    $report = LeakReport::fromArray(unactionableSubwordReport(), actsOnSuspects: true);

    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('"start"')
        ->and($serialized)->not->toContain('"end"')
        ->and($serialized)->not->toContain('UnactionableSubword');
});

it('counts only UnactionableSubword telemetry rows and tolerates malformed telemetry', function () {
    $payload = nymLeakReport0151();
    $payload['telemetry'] = [
        ['kind' => 'LocaleSkipped', 'safety_net_id' => 'nym-small-int8', 'document_kind' => 'text'],
        'not-a-row',
        ['kind' => 'UnactionableSubword', 'safety_net_id' => 'nym-small-int8', 'class' => 'name', 'start' => 0, 'end' => 3, 'document_kind' => 'text'],
    ];

    expect(LeakReport::fromArray($payload, actsOnSuspects: true)->unactionableSubwordCount)->toBe(1);
    expect(LeakReport::fromArray(['telemetry' => 'nope'], actsOnSuspects: true)->unactionableSubwordCount)->toBe(0);
});

it('treats a class-mismatch-only report as covered: Unverified, not Suspect (what upstream strict ships)', function () {
    // Upstream strict refuses only uncovered / partial-bleed suspects; a
    // ClassMismatch is already covered by a token of another class and ships
    // with a stderr warning. That is the only report a successful strict clean
    // can carry.
    $report = LeakReport::fromArray(leakReportArray(
        ['suspect_count' => 1, 'class_mismatch_count' => 1],
        [[
            'safety_net_id' => 'nym-small-int8',
            'raw_label' => 'DATE_OF_BIRTH>=0.9',
            'mapped_class' => 'Custom:date',
            'leak_kind' => 'class_mismatch',
            'pipeline_class' => 'Custom:date_of_birth',
            'span_len' => 10,
        ]],
    ));

    expect($report->hasSuspectedLeak())->toBeFalse()
        ->and($report->hasResolvedSuspects())->toBeFalse()
        ->and($report->coverageState())->toBe(CoverageState::Unverified);
});

it('never reports Verified while a suspect exists, even one of an unknown leak kind', function (bool $actsOnSuspects, CoverageState $expected, bool $resolved) {
    // A future upstream LeakKind serialises as leak_kind "unknown": counted in
    // suspect_count but in none of the gap counts.
    $report = LeakReport::fromArray(leakReportArray(['suspect_count' => 1]), $actsOnSuspects);

    expect($report->coverageState())->toBe($expected)
        ->and($report->hasResolvedSuspects())->toBe($resolved);
})->with([
    'acting decision: protected, amber' => [true, CoverageState::Unverified, true],
    'observe decision: possibly raw, red' => [false, CoverageState::Suspect, false],
]);

it('parses the leak_report stats counts from the upstream shape', function () {
    $report = LeakReport::fromArray(leakReportArray([
        'suspect_count' => 2,
        'uncovered_count' => 3,
        'partial_bleed_count' => 4,
        'class_mismatch_count' => 5,
        'locale_skipped_count' => 6,
    ]));

    expect($report->suspectCount)->toBe(2)
        ->and($report->uncoveredCount)->toBe(3)
        ->and($report->partialBleedCount)->toBe(4)
        ->and($report->classMismatchCount)->toBe(5)
        ->and($report->localeSkippedCount)->toBe(6);
});

it('parses suspects into LeakSuspect metadata DTOs', function () {
    $report = LeakReport::fromArray(leakReportArray(
        ['suspect_count' => 1, 'class_mismatch_count' => 1],
        [[
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'raw_label' => 'private_person',
            'mapped_class' => 'Name',
            'leak_kind' => 'class_mismatch',
            'pipeline_class' => 'Email',
            'span_len' => 17,
            'field_path' => 'body.note',
            'score' => 0.92,
        ]],
    ));

    expect($report->suspects)->toHaveCount(1)
        ->and($report->suspects[0])->toBeInstanceOf(LeakSuspect::class)
        ->and($report->suspects[0]->safetyNetId)->toBe('openai-privacy-filter-subprocess')
        ->and($report->suspects[0]->rawLabel)->toBe('private_person')
        ->and($report->suspects[0]->mappedClass)->toBe('Name')
        ->and($report->suspects[0]->leakKind)->toBe('class_mismatch')
        ->and($report->suspects[0]->pipelineClass)->toBe('Email')
        ->and($report->suspects[0]->spanLen)->toBe(17)
        ->and($report->suspects[0]->fieldPath)->toBe('body.note')
        ->and($report->suspects[0]->score)->toBe(0.92);
});

it('defaults to zero counts and empty suspects on absent or malformed fields', function () {
    expect(LeakReport::fromArray([])->suspectCount)->toBe(0);
    expect(LeakReport::fromArray([])->suspects)->toBe([]);
    expect(LeakReport::fromArray(['stats' => 'nonsense', 'suspects' => 'nope'])->uncoveredCount)->toBe(0);
    expect(LeakReport::fromArray(['suspects' => ['not-an-object', 42]])->suspects)->toBe([]);

    $suspect = LeakSuspect::fromArray([]);
    expect($suspect->safetyNetId)->toBe('')
        ->and($suspect->pipelineClass)->toBeNull()
        ->and($suspect->spanLen)->toBe(0)
        ->and($suspect->fieldPath)->toBeNull()
        ->and($suspect->score)->toBeNull();
});

it('reports Verified only when there are no suspects and full coverage', function () {
    $report = LeakReport::fromArray(leakReportArray());

    expect($report->coverageState())->toBe(CoverageState::Verified)
        ->and($report->hasSuspectedLeak())->toBeFalse();
});

it('reports Unverified when coverage is partial and no suspects are flagged', function (string $gapField) {
    $report = LeakReport::fromArray(leakReportArray([$gapField => 1]));

    expect($report->coverageState())->toBe(CoverageState::Unverified)
        ->and($report->hasSuspectedLeak())->toBeFalse();
})->with([
    'uncovered' => 'uncovered_count',
    'partial bleed' => 'partial_bleed_count',
    'class mismatch' => 'class_mismatch_count',
    'locale skipped' => 'locale_skipped_count',
]);

it('reports Suspect when the safety net actively flags a possible leak', function () {
    $report = LeakReport::fromArray(leakReportArray(['suspect_count' => 1]));

    expect($report->coverageState())->toBe(CoverageState::Suspect)
        ->and($report->hasSuspectedLeak())->toBeTrue();
});

it('prioritises Suspect over Unverified when both suspects and gaps are present', function () {
    $report = LeakReport::fromArray(leakReportArray([
        'suspect_count' => 1,
        'uncovered_count' => 3,
    ]));

    expect($report->coverageState())->toBe(CoverageState::Suspect);
});

it('exposes replay_hash when present and null otherwise', function () {
    expect(LeakReport::fromArray(leakReportArray())->replayHash)->toBeNull();
    expect(LeakReport::fromArray(leakReportArray([], [], 'a1b2c3'))->replayHash)->toBe('a1b2c3');
});

it('carries only safe metadata — never raw PII from a hostile leak_report', function () {
    // A defensive fixture: a future/compromised binary that smuggles raw PII and
    // byte offsets into a suspect element alongside the safe metadata fields.
    // The DTO must read ONLY its allowlist, so the secret values never survive.
    $secret = 'alice@secret.example';

    $report = LeakReport::fromArray(leakReportArray(
        ['suspect_count' => 1],
        [[
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'raw_label' => 'private_email',
            'mapped_class' => 'Email',
            'leak_kind' => 'uncovered',
            'span_len' => 20,
            // Hostile extras that must be dropped:
            'raw' => $secret,
            'text' => $secret,
            'value' => $secret,
            'source_text' => $secret,
            'span' => ['start' => 8, 'end' => 28],
            'start' => 8,
            'end' => 28,
        ]],
    ));

    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain($secret)
        ->and($serialized)->not->toContain('source_text')
        ->and($serialized)->not->toContain('"start"')
        ->and($serialized)->not->toContain('"end"')
        // ...while the safe metadata is retained:
        ->and($report->suspects[0]->rawLabel)->toBe('private_email')
        ->and($report->suspects[0]->mappedClass)->toBe('Email')
        ->and($report->suspects[0]->spanLen)->toBe(20);
});
