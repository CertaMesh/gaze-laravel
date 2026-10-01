<?php

declare(strict_types=1);

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\LeakReport;
use Illuminate\Support\Facades\Process;

/**
 * @param  array<string, int>  $stats
 * @param  list<array<string, mixed>>  $suspects
 */
function cleanOutputWithLeakReport(array $stats = [], array $suspects = []): string
{
    return json_encode([
        'clean_text' => 'Hello Name_1',
        'session_blob' => 'blob-bytes',
        'stats' => ['detections' => 1],
        'leak_report' => [
            'stats' => array_merge([
                'suspect_count' => 0,
                'uncovered_count' => 0,
                'partial_bleed_count' => 0,
                'class_mismatch_count' => 0,
                'locale_skipped_count' => 0,
            ], $stats),
            'suspects' => $suspects,
            'telemetry' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('parses leak_report from the clean response into the session', function () {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithLeakReport(['uncovered_count' => 2])),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->leakReport)->toBeInstanceOf(LeakReport::class)
        ->and($session->leakReport->uncoveredCount)->toBe(2)
        ->and($session->detections)->toBe(1);
});

it('leaves leakReport null when the clean response omits it', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->leakReport)->toBeNull();
});

it('treats a session with no leak_report as Unverified — never asserts a green it cannot back', function () {
    $session = new GazeSession(
        cleanText: 'Hello Name_1',
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 5,
    );

    expect($session->leakReport)->toBeNull()
        ->and($session->coverageState())->toBe(CoverageState::Unverified)
        ->and($session->hasSuspectedLeak())->toBeFalse();
});

it('delegates session coverageState and hasSuspectedLeak to the leak_report', function (array $stats, CoverageState $expected, bool $suspected) {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithLeakReport($stats)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->coverageState())->toBe($expected)
        ->and($session->hasSuspectedLeak())->toBe($suspected);
})->with([
    'all zero is Verified' => [[], CoverageState::Verified, false],
    'partial coverage is Unverified' => [['partial_bleed_count' => 1], CoverageState::Unverified, false],
    // Default config forwards no mode: upstream resolve + redact acted on the
    // flagged span, so it is protected — amber, not red (#160).
    'flagged and protected suspect is Unverified' => [['suspect_count' => 1, 'uncovered_count' => 1], CoverageState::Unverified, false],
]);

/**
 * Real `gaze clean --format=json` output of the 0.15.1 release binary with the
 * Nym net, input `Invoice date 1971-05-30, plate B-MW 1234` (synthetic). The
 * clean text differs per mode; the leak_report is byte-identical across all of
 * them. session_blob and entries are replaced: the fixture needs neither.
 */
function nymCleanOutput0151(string $cleanText): string
{
    return json_encode([
        'clean_text' => $cleanText,
        'session_blob' => 'blob-bytes',
        'entries' => [],
        'stats' => ['detections' => 0],
        'leak_report' => json_decode(<<<'JSON'
{"stats":{"suspect_count":2,"uncovered_count":2,"partial_bleed_count":0,"class_mismatch_count":0,"locale_skipped_count":0},"suspects":[{"safety_net_id":"nym-small-int8","raw_label":"DATE_OF_BIRTH>=0.9","mapped_class":"Custom:date","leak_kind":"uncovered","span_len":10,"score":0.9992361},{"safety_net_id":"nym-small-int8","raw_label":"LICENSE_PLATE>=0.5","mapped_class":"Custom:license_plate","leak_kind":"uncovered","span_len":9,"score":0.99993986}],"telemetry":[]}
JSON, true, 512, JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR);
}

it('derives the trust state from the safety-net decision it forwarded (#160)', function (
    ?string $mode,
    ?string $fallback,
    string $cleanText,
    CoverageState $expected,
    bool $suspected,
) {
    Process::fake([
        '*' => Process::result(output: nymCleanOutput0151($cleanText)),
    ]);

    $session = $this->makeGaze(
        safetyNet: true,
        safetyNetBackend: 'nym',
        safetyNetMode: $mode,
        safetyNetFallback: $fallback,
    )->clean('Invoice date 1971-05-30, plate B-MW 1234');

    expect($session->leakReport?->suspectCount)->toBe(2)
        ->and($session->coverageState())->toBe($expected)
        ->and($session->hasSuspectedLeak())->toBe($suspected)
        ->and($session->leakReport?->hasResolvedSuspects())->toBe(! $suspected);
})->with([
    'default (upstream resolve + redact)' => [null, null, 'Invoice date <7c7c7685:Custom:date_1>, plate <7c7c7685:Custom:license_plate_1>', CoverageState::Unverified, false],
    'resolve' => ['resolve', null, 'Invoice date <7c7c7685:Custom:date_1>, plate <7c7c7685:Custom:license_plate_1>', CoverageState::Unverified, false],
    'resolve + strict fallback' => ['resolve', 'strict', 'Invoice date <d9160e20:Custom:date_1>, plate <d9160e20:Custom:license_plate_1>', CoverageState::Unverified, false],
    'redact' => ['redact', null, 'Invoice date [REDACTED:custom:date], plate [REDACTED:custom:license-plate]', CoverageState::Unverified, false],
    // A residual may ship raw under the tolerant fallback and the report cannot
    // say which suspect it was, so this stays red.
    'resolve + tolerant fallback' => ['resolve', 'tolerant', 'Invoice date <13a274fd:Custom:date_1>, plate <13a274fd:Custom:license_plate_1>', CoverageState::Suspect, true],
    'tolerant (raw output)' => ['tolerant', null, 'Invoice date 1971-05-30, plate B-MW 1234', CoverageState::Suspect, true],
]);

it('exposes the trust state through the faked Gaze facade', function () {
    Gaze::fake(cleanHandler: fn (string $text): GazeSession => new GazeSession(
        cleanText: 'Hello Name_1',
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 1,
        leakReport: LeakReport::fromArray([
            'stats' => ['suspect_count' => 1],
            'suspects' => [[
                'safety_net_id' => 'openai-privacy-filter-subprocess',
                'raw_label' => 'private_email',
                'mapped_class' => 'Email',
                'leak_kind' => 'uncovered',
                'span_len' => 12,
            ]],
        ]),
    ));

    $session = Gaze::clean('Hello Alice');

    expect($session->coverageState())->toBe(CoverageState::Suspect)
        ->and($session->hasSuspectedLeak())->toBeTrue()
        ->and($session->leakReport)->toBeInstanceOf(LeakReport::class);

    assert($session->leakReport instanceof LeakReport);
    expect($session->leakReport->suspects[0]->mappedClass)->toBe('Email');
});
