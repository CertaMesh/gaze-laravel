<?php

declare(strict_types=1);

use Carbon\Carbon;
use CertaMesh\Gaze\Audit\SafetyNetQueryBuilder;
use Illuminate\Support\Facades\Process;

it('runs gaze audit safety-net query against the audit db', function () {
    Process::fake([
        '*' => Process::result(output: "id\tleak_kind\n1\tfresh\n"),
    ]);

    $rows = $this->makeGaze()->audit('/tmp/audit.sqlite')->safetyNetQuery()->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'safety-net',
            'query',
            '--audit-db=/tmp/audit.sqlite',
        ]);

        return true;
    });

    expect($rows)->toBe([
        ['id', 'leak_kind'],
        ['1', 'fresh'],
    ]);
});

it('forwards each safety-net value filter as its upstream flag', function (string $method, string $value, string $flag) {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->safetyNetQuery()->{$method}($value)->execute();

    Process::assertRan(function ($process) use ($flag, $value): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'safety-net',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            $flag.'='.$value,
        ]);

        return true;
    });
})->with([
    'whereLeakKind → --leak-kind' => ['whereLeakKind', 'fresh', '--leak-kind'],
    'whereRawLabel → --raw-label' => ['whereRawLabel', 'PER', '--raw-label'],
    'whereMappedClass → --mapped-class' => ['whereMappedClass', 'email', '--mapped-class'],
    'whereFieldPath → --field-path' => ['whereFieldPath', 'user.email', '--field-path'],
    'from → --from' => ['from', '2026-01-01T00:00:00Z', '--from'],
    'to → --to' => ['to', '2026-02-01T00:00:00Z', '--to'],
]);

it('assembles all safety-net filters in the upstream --help order regardless of call order', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->safetyNetQuery()
        ->to('2026-02-01T00:00:00Z')
        ->from('2026-01-01T00:00:00Z')
        ->whereFieldPath('user.email')
        ->whereMappedClass('email')
        ->whereRawLabel('PER')
        ->whereLeakKind('fresh')
        ->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'safety-net',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            '--leak-kind=fresh',
            '--raw-label=PER',
            '--mapped-class=email',
            '--field-path=user.email',
            '--from=2026-01-01T00:00:00Z',
            '--to=2026-02-01T00:00:00Z',
        ]);

        return true;
    });
});

it('normalises Carbon from()/to() bounds to ISO 8601 UTC Zulu', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->safetyNetQuery()
        ->from(Carbon::parse('2026-01-01 01:00:00', 'Europe/Dublin'))
        ->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--from=2026-01-01T01:00:00Z');

        return true;
    });
});

it('every safety-net filter method is fluent and returns the same builder', function () {
    $builder = $this->makeGaze()->audit('/tmp/audit.sqlite')->safetyNetQuery();

    expect($builder)->toBeInstanceOf(SafetyNetQueryBuilder::class)
        ->and($builder->whereLeakKind('fresh'))->toBe($builder)
        ->and($builder->whereRawLabel('PER'))->toBe($builder)
        ->and($builder->whereMappedClass('email'))->toBe($builder)
        ->and($builder->whereFieldPath('user.email'))->toBe($builder)
        ->and($builder->from('2026-01-01T00:00:00Z'))->toBe($builder)
        ->and($builder->to('2026-02-01T00:00:00Z'))->toBe($builder);
});
