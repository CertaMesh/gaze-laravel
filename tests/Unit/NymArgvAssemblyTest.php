<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use Illuminate\Support\Facades\Process;

/*
 * `--nym-model-dir` / `--nym-intra-threads` on the one-shot clean path (#157).
 *
 * gaze 0.15.1 rejects both flags unless a safety net is activated: with the
 * net off it exits 3 with {"error":"SafetyNetConfig","detail":"safety-net
 * backend options require --safety-net=<kind> activation"}. The adapter
 * therefore forwards them ONLY while the enabled net selects `nym` (the same
 * rule as the backend selector), so a leftover GAZE_NYM_MODEL_DIR on a
 * disabled net stays inert.
 */

function nym_fakeCleanResult(): void
{
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);
}

it('forwards the nym knobs last, after the safety-net family, when the enabled net selects nym', function () {
    nym_fakeCleanResult();

    $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        safetyNet: true,
        safetyNetTimeoutMs: 7500,
        safetyNetMode: 'resolve',
        safetyNetBackend: 'nym',
        safetyNetFallback: 'redact',
        nymModelDir: '/srv/gaze/gaze/models/nym-small-int8',
        nymIntraThreads: 2,
    )->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--safety-net=openai-filter',
            '--safety-net-timeout-ms=7500',
            '--safety-net-mode=resolve',
            '--safety-net-backend=nym',
            '--safety-net-fallback=redact',
            '--nym-model-dir=/srv/gaze/gaze/models/nym-small-int8',
            '--nym-intra-threads=2',
        ]);

        return true;
    });
});

it('forwards each nym knob on its own when only one is set', function (?string $modelDir, ?int $threads, string $expected) {
    nym_fakeCleanResult();

    $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        safetyNet: true,
        safetyNetBackend: 'nym',
        nymModelDir: $modelDir,
        nymIntraThreads: $threads,
    )->clean('Hello');

    Process::assertRan(function ($process) use ($expected): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--safety-net=openai-filter',
            '--safety-net-backend=nym',
            $expected,
        ]);

        return true;
    });
})->with([
    'model dir only' => ['/srv/nym', null, '--nym-model-dir=/srv/nym'],
    'intra threads only' => [null, 4, '--nym-intra-threads=4'],
]);

it('forwards the nym knobs only while the enabled net selects nym', function () {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = array_slice($process->command, 4); // drop binary, verb, --policy, --format

        return Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR));
    });

    $states = [
        'net on, nym' => [true, 'nym'],
        // gaze 0.15.1 exits 3 with SafetyNetConfig "safety-net backend options
        // require --safety-net=<kind> activation" if the knobs ride along here.
        'net off, backend nym' => [false, 'nym'],
        'net off, no backend' => [false, null],
        'net on, openai-filter backend' => [true, 'openai-filter'],
        'net on, default backend' => [true, null],
        // Upstream parses the selector case-sensitively and rejects `Nym` itself.
        'net on, mis-cased selector' => [true, 'Nym'],
    ];
    foreach ($states as [$enabled, $backend]) {
        $this->makeGaze(safetyNet: $enabled, safetyNetBackend: $backend, nymModelDir: '/srv/nym', nymIntraThreads: 2)->clean('Hello');
    }

    expect(array_combine(array_keys($states), $commands))->toBe([
        'net on, nym' => ['--safety-net=openai-filter', '--safety-net-backend=nym', '--nym-model-dir=/srv/nym', '--nym-intra-threads=2'],
        'net off, backend nym' => [],
        'net off, no backend' => [],
        'net on, openai-filter backend' => ['--safety-net=openai-filter', '--safety-net-backend=openai-filter'],
        'net on, default backend' => ['--safety-net=openai-filter'],
        'net on, mis-cased selector' => ['--safety-net=openai-filter', '--safety-net-backend=Nym'],
    ]);
});

it('refuses a non-positive nym intra_threads before spawning, but only while it would be forwarded', function (int $threads) {
    nym_fakeCleanResult();

    // Inert on a disabled net: the flag is never forwarded there.
    $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: false, safetyNetBackend: 'nym', nymIntraThreads: $threads)->clean('Hello');
    Process::assertRanTimes(fn ($process) => $process->command === ['/fake/gaze', 'clean', '--policy=/tmp/policy.toml', '--format=json'], 1);

    try {
        $this->makeGaze(safetyNet: true, safetyNetBackend: 'nym', nymIntraThreads: $threads)->clean('Hello');
    } catch (GazeSafetyNetConfigException $e) {
        expect($e->getMessage())
            ->toBe("gaze.safety_net.nym.intra_threads must be a positive integer, got {$threads} (pre-flight). Unset GAZE_NYM_INTRA_THREADS to keep the upstream default of 1.")
            ->and($e->exitCode)->toBe(2)
            ->and($e->stderrHash)->toBeNull();

        Process::assertRanTimes(fn () => true, 1); // the refused clean never spawned

        return;
    }

    $this->fail('Expected GazeSafetyNetConfigException to be thrown.');
})->with(['zero' => [0], 'negative' => [-1]]);
