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
    'flagged suspect is Suspect' => [['suspect_count' => 1], CoverageState::Suspect, true],
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
