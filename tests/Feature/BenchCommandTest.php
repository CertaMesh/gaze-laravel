<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

function benchCleanSession(): GazeSession
{
    return new GazeSession(
        cleanText: 'Hallo, ich bin <NAME_1> (<EMAIL_1>). Bitte storniere Bestellung ORD-DEMO-77.',
        ciphertext: EncryptedBlob::wrap(base64_encode(json_encode([
            'text' => 'Hallo, ich bin Anna Schmidt (anna@example.de). Bitte storniere Bestellung ORD-DEMO-77.',
        ], JSON_THROW_ON_ERROR))),
        detections: 2,
    );
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function runBenchJson(array $arguments): array
{
    $exitCode = Artisan::call('gaze:bench', $arguments + ['--json' => true]);

    expect($exitCode)->toBe(0);

    /** @var array<string, mixed> $json */
    $json = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    return $json;
}

it('registers gaze:bench artisan command', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('gaze:bench');
});

it('emits a versioned cold baseline JSON object with chronological samples', function () {
    $input = 'Hallo, ich bin Anna Schmidt (anna@example.de). Bitte storniere Bestellung ORD-DEMO-77.';
    $this->bindCountingGaze(benchCleanSession(), expectedCalls: 2, expectedInput: $input);

    $json = runBenchJson([
        '--requests' => 2,
        '--text' => $input,
    ]);

    expect(array_keys($json))->toBe([
        'bench_schema_version',
        'mode',
        'requests',
        'first_ms',
        'total_wall_ms',
        'p50_ms',
        'p95_ms',
        'p99_ms',
        'samples_ms',
        'meta',
    ]);
    expect($json['bench_schema_version'])->toBe(1)
        ->and($json['mode'])->toBe('cold')
        ->and($json['requests'])->toBe(2)
        ->and($json['samples_ms'])->toHaveCount(2)
        ->and($json['first_ms'])->toBe($json['samples_ms'][0]);
    expect(array_keys($json['meta']))->toBe([
        'php',
        'gaze_version',
        'sapi',
        'os',
    ]);
});

it('emits first and last sample windows by default for large request counts', function () {
    $this->bindCountingGaze(benchCleanSession(), expectedCalls: 1000);

    $json = runBenchJson([
        '--requests' => 1000,
    ]);

    expect($json['samples_ms'])->toHaveCount(200)
        ->and($json['first_ms'])->toBe($json['samples_ms'][0]);
});

it('can suppress samples for large request counts', function () {
    $this->bindCountingGaze(benchCleanSession(), expectedCalls: 1000);

    $json = runBenchJson([
        '--requests' => 1000,
        '--samples' => 'none',
    ]);

    expect($json['samples_ms'])->toBe([])
        ->and(is_int($json['first_ms']) || is_float($json['first_ms']))->toBeTrue();
});
