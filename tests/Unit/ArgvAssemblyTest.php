<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('assembles clean argv with policy and json output', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello Alice');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
        ])
            ->and($process->input)->toBe('Hello Alice');

        return true;
    });
});

it('assembles restore argv and sends the session envelope', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);

    $this->makeGaze()->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'restore',
            '--format=json',
        ]);

        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->toBe([
            'session_blob' => 'blob',
            'text' => 'Hello Name_1',
        ]);

        return true;
    });
});
