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

/**
 * A clean response whose report came through the `resolve` decision's `redact`
 * fallback. Two shapes:
 *
 *  - `nym`: real 0.15.1 release-binary output with the Nym net, default mode,
 *    input `admin_root930 May 1971AB12 CDE` (synthetic). Two resolve rounds
 *    tokenize `1971AB12` and `CDE`, the fallback marks `root930 May`; here
 *    every span happens to be protected.
 *  - `terminal-raw`: upstream's own fixture (gaze v0.15.1
 *    crates/gaze/tests/terminal_admission.rs,
 *    `terminal_round_happens_at_most_once_and_reports_what_it_could_not_act_on`):
 *    `alpha bravo charlie delta`, the fallback marks `charlie`, the terminal
 *    round tokenizes `delta`, and the final scan's `bravo` ships RAW — in the
 *    report, with no telemetry row. The report alone looks exactly like the
 *    protected `nym` one.
 */
function fallbackCleanOutput(string $shape): string
{
    $suspect = fn (string $net, string $label, string $class, int $len, float $score): array => [
        'safety_net_id' => $net,
        'raw_label' => $label,
        'mapped_class' => $class,
        'leak_kind' => 'uncovered',
        'span_len' => $len,
        'score' => $score,
    ];

    [$cleanText, $suspects] = match ($shape) {
        'nym' => [
            'admin_[REDACTED:custom:license-plate] <1cc914e6:Custom:license_plate_1> <1cc914e6:Custom:license_plate_2>',
            [
                $suspect('nym-small-int8', 'LICENSE_PLATE>=0.5', 'Custom:license_plate', 8, 0.56586945),
                $suspect('nym-small-int8', 'LICENSE_PLATE>=0.5', 'Custom:license_plate', 3, 0.56680036),
                $suspect('nym-small-int8', 'LICENSE_PLATE>=0.5', 'Custom:license_plate', 11, 0.726112),
            ],
        ],
        // alpha (second batch), charlie (fallback), delta (terminal round),
        // bravo (final scan, raw) — in the order upstream appends them.
        'terminal-raw' => [
            '<1cc914e6:Name_1> bravo [REDACTED:name] <1cc914e6:Name_2>',
            [
                $suspect('terminal.fixture', 'synthetic', 'Name', 5, 1.0),
                $suspect('terminal.fixture', 'synthetic', 'Name', 7, 1.0),
                $suspect('terminal.fixture', 'synthetic', 'Name', 5, 1.0),
                $suspect('terminal.fixture', 'synthetic', 'Name', 5, 1.0),
            ],
        ],
        default => throw new InvalidArgumentException("Unknown fallback fixture shape: {$shape}"),
    };

    return json_encode([
        'clean_text' => $cleanText,
        'session_blob' => 'blob-bytes',
        'entries' => [],
        'stats' => ['detections' => 0],
        'leak_report' => [
            'stats' => [
                'suspect_count' => count($suspects),
                'uncovered_count' => count($suspects),
                'partial_bleed_count' => 0,
                'class_mismatch_count' => 0,
                'locale_skipped_count' => 0,
            ],
            'suspects' => $suspects,
            'telemetry' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('reads a report from a resolve + redact fallback run as Suspect: its final scan ships raw', function (?string $mode, ?string $fallback, string $shape) {
    Process::fake([
        '*' => Process::result(output: fallbackCleanOutput($shape)),
    ]);

    $session = $this->makeGaze(
        safetyNet: true,
        safetyNetBackend: 'nym',
        safetyNetMode: $mode,
        safetyNetFallback: $fallback,
    )->clean('synthetic input');

    // The fallback's marker in the clean text: the report may hold a final-scan
    // finding upstream admitted raw, so no suspect in it is vouched for.
    expect($session->cleanText)->toContain('[REDACTED:')
        ->and($session->leakReport?->actsOnSuspects)->toBeFalse()
        ->and($session->hasSuspectedLeak())->toBeTrue()
        ->and($session->leakReport?->hasResolvedSuspects())->toBeFalse()
        ->and($session->coverageState())->toBe(CoverageState::Suspect);
})->with([
    'default, real Nym fallback (all protected: a false red, never a false amber)' => [null, null, 'nym'],
    'default, upstream fixture with a raw final-scan finding' => [null, null, 'terminal-raw'],
    'resolve + redact spelled out' => ['resolve', 'redact', 'terminal-raw'],
    'resolve, fallback defaulted' => ['resolve', null, 'terminal-raw'],
]);

it('keeps acting semantics where a marker cannot mean a fallback run', function (string $mode, ?string $fallback) {
    // `redact` writes markers by design and never rescans; `resolve` + `strict`
    // refuses (exit 3, Pipeline) instead of running a fallback, so a marker
    // there is input or policy text, not a fallback trace.
    Process::fake([
        '*' => Process::result(output: fallbackCleanOutput('nym')),
    ]);

    $session = $this->makeGaze(
        safetyNet: true,
        safetyNetBackend: 'nym',
        safetyNetMode: $mode,
        safetyNetFallback: $fallback,
    )->clean('synthetic input');

    expect($session->leakReport?->actsOnSuspects)->toBeTrue()
        ->and($session->hasSuspectedLeak())->toBeFalse()
        ->and($session->leakReport?->hasResolvedSuspects())->toBeTrue()
        ->and($session->coverageState())->toBe(CoverageState::Unverified);
})->with([
    'redact' => ['redact', null],
    'redact + redact fallback' => ['redact', 'redact'],
    'resolve + strict' => ['resolve', 'strict'],
]);

it('reads any [REDACTED: in a resolve + redact clean text as a fallback run, including typed input', function () {
    // A document that already carries the marker text costs a false red: the
    // adapter cannot tell it from the fallback's own marker, and amber is the
    // wrong way to be wrong.
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Ticket [REDACTED:name] closed, plate <1cc914e6:Custom:license_plate_1>',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 0],
            'leak_report' => json_decode(fallbackCleanOutput('nym'), true, 512, JSON_THROW_ON_ERROR)['leak_report'],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze(safetyNet: true, safetyNetBackend: 'nym')->clean('synthetic input');

    expect($session->coverageState())->toBe(CoverageState::Suspect)
        ->and($session->leakReport?->actsOnSuspects)->toBeFalse();
});

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
