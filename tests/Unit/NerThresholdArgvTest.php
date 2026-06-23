<?php

declare(strict_types=1);

use CertaMesh\Gaze\GazeSession;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);
});

it('forwards --ner-threshold from a per-call argument', function () {
    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello', 0.5);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--ner-threshold=0.5');

        return true;
    });
});

it('forwards --ner-threshold from the configured default when no per-call argument is given', function () {
    $this->makeGaze(policyPath: '/tmp/policy.toml', nerThreshold: 0.25)->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--ner-threshold=0.25');

        return true;
    });
});

it('lets a per-call argument win over the configured default', function () {
    $this->makeGaze(policyPath: '/tmp/policy.toml', nerThreshold: 0.2)->clean('Hello', 0.9);

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--ner-threshold=0.9')
            ->not->toContain('--ner-threshold=0.2');

        return true;
    });
});

it('omits --ner-threshold when neither a per-call argument nor config is set', function () {
    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello');

    Process::assertRan(function ($process): bool {
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--ner-threshold=');
        }

        return true;
    });
});

it('accepts the inclusive 0.0 and 1.0 bounds without throwing', function (float $bound) {
    $session = $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello', $bound);

    expect($session)->toBeInstanceOf(GazeSession::class);
})->with([0.0, 1.0]);

it('throws InvalidArgumentException for an out-of-range per-call threshold', function (float $bad) {
    expect(fn () => $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello', $bad))
        ->toThrow(InvalidArgumentException::class);
})->with([-0.1, 1.5, 2.0]);

it('throws InvalidArgumentException for an out-of-range configured threshold', function () {
    expect(fn () => $this->makeGaze(policyPath: '/tmp/policy.toml', nerThreshold: 1.5)->clean('Hello'))
        ->toThrow(InvalidArgumentException::class);
});
