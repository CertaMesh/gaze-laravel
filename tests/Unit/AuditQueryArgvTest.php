<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Audit\QueryBuilder;

it('forwards --restore-events when onlyRestoreEvents() is set', function () {
    Process::fake([
        '*' => Process::result(output: "row1col1\trow1col2\n"),
    ]);

    $rows = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->onlyRestoreEvents()->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            '--restore-events',
        ]);

        return true;
    });

    expect($rows)->toBe([['row1col1', 'row1col2']]);
});

it('omits --restore-events when onlyRestoreEvents() is not called', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->not->toContain('--restore-events');

        return true;
    });
});

it('onlyRestoreEvents() is fluent and returns the QueryBuilder', function () {
    $builder = $this->makeGaze()->audit('/tmp/audit.sqlite')->query();

    expect($builder->onlyRestoreEvents())->toBe($builder)
        ->toBeInstanceOf(QueryBuilder::class);
});
