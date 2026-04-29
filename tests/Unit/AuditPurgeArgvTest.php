<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Audit\AuditPurgeResult;

it('assembles audit purge --dry-run argv with ISO8601 string before', function () {
    Process::fake([
        '*' => Process::result(output: "0 rows would be purged\n"),
    ]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->before('2026-01-01T00:00:00Z')->dryRun();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'purge',
            '--audit-db=/tmp/audit.sqlite',
            '--before=2026-01-01T00:00:00Z',
            '--dry-run',
        ]);

        return true;
    });

    expect($result)->toBeInstanceOf(AuditPurgeResult::class);
    expect($result->rawOutput)->toBe("0 rows would be purged\n");
    expect($result->count)->toBe(0);
});

it('assembles audit purge execute argv (no --dry-run flag)', function () {
    Process::fake([
        '*' => Process::result(output: "5 rows purged\n"),
    ]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->before('2026-01-01T00:00:00Z')->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'purge',
            '--audit-db=/tmp/audit.sqlite',
            '--before=2026-01-01T00:00:00Z',
        ]);
        expect($process->command)->not->toContain('--dry-run');

        return true;
    });

    expect($result->rawOutput)->toBe("5 rows purged\n");
    expect($result->count)->toBe(5);
});

it('rawOutput is always populated; count is null when stdout has no row pattern', function () {
    Process::fake([
        '*' => Process::result(output: "completed without count\n"),
    ]);

    $result = $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->before('2026-01-01T00:00:00Z')->execute();

    expect($result->rawOutput)->toBe("completed without count\n");
    expect($result->count)->toBeNull();
});

it('accepts a Carbon instance for before() and serializes to ISO8601', function () {
    Process::fake(['*' => Process::result(output: "0 rows purged\n")]);

    $cutoff = Carbon::create(2026, 4, 1, 0, 0, 0, 'UTC');
    $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->before($cutoff)->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--before=2026-04-01T00:00:00Z');

        return true;
    });
});

it('throws when execute()/dryRun() called without before()', function () {
    expect(fn () => $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->dryRun())
        ->toThrow(LogicException::class, 'PurgeBuilder::before()');
});

it('rejects a non-Carbon, non-string before() argument', function () {
    expect(fn () => $this->makeGaze()->audit('/tmp/audit.sqlite')->purge()->before(1234567890))
        ->toThrow(TypeError::class);
});
