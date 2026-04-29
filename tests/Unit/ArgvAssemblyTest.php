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

    $this->makeGaze(maxBytes: 1024)->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'restore',
            '--format=json',
            '--max-bytes=1024',
        ]);

        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->toBe([
            'session_blob' => 'blob',
            'text' => 'Hello Name_1',
        ]);

        return true;
    });
});

it('forwards --audit-db when configured on clean argv', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        auditDbPath: '/tmp/audit.sqlite',
    )->clean('Hello Alice');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--audit-db=/tmp/audit.sqlite',
        ]);

        return true;
    });
});

it('omits --audit-db when audit_db_path is null', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->not->toContain('--audit-db');
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--audit-db=');
        }

        return true;
    });
});
