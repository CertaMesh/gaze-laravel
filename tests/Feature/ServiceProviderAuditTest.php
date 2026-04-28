<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Gaze;

it('Gaze resolved from container forwards configured audit_db_path on clean argv', function () {
    config([
        'gaze.audit_db_path' => '/tmp/from-container.sqlite',
        'gaze.binary' => '/fake/gaze',
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->app->make(Gaze::class)->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--audit-db=/tmp/from-container.sqlite');

        return true;
    });
});

it('Gaze::audit() resolved from container uses bound AuditService with configured path', function () {
    config([
        'gaze.audit_db_path' => '/tmp/from-container-audit.sqlite',
        'gaze.binary' => '/fake/gaze',
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake(['*' => Process::result(output: "0 rows would be purged\n")]);

    $this->app->make(Gaze::class)
        ->audit()
        ->purge()
        ->before('2026-01-01T00:00:00Z')
        ->dryRun();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--audit-db=/tmp/from-container-audit.sqlite');

        return true;
    });
});
