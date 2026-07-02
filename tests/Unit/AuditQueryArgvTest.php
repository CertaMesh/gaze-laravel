<?php

declare(strict_types=1);

use Carbon\Carbon;
use CertaMesh\Gaze\Audit\QueryBuilder;
use Illuminate\Support\Facades\Process;

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

it('forwards each value filter as its upstream flag', function (string $method, string $value, string $flag) {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->{$method}($value)->execute();

    Process::assertRan(function ($process) use ($flag, $value): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            $flag.'='.$value,
        ]);

        return true;
    });
})->with([
    'whereClass → --class' => ['whereClass', 'email', '--class'],
    'whereSource → --source' => ['whereSource', 'email.global', '--source'],
    'whereAction → --action' => ['whereAction', 'tokenize', '--action'],
    'whereDocumentKind → --document-kind' => ['whereDocumentKind', 'structured', '--document-kind'],
    'from → --from' => ['from', '2026-01-01T00:00:00Z', '--from'],
    'to → --to' => ['to', '2026-02-01T00:00:00Z', '--to'],
    'whereSession → --session' => ['whereSession', '019f21f6-8edc-71a3-873e-02d1b327f77b', '--session'],
    'whereAmbiguityReason → --ambiguity-reason' => ['whereAmbiguityReason', 'no-anchor', '--ambiguity-reason'],
    'whereCollisionFamily → --collision-family' => ['whereCollisionFamily', 'fam-1', '--collision-family'],
    'whereCollisionVariant → --collision-variant' => ['whereCollisionVariant', 'var-2', '--collision-variant'],
]);

it('forwards --has-ambiguity as a bare flag', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->hasAmbiguity()->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            '--has-ambiguity',
        ]);

        return true;
    });
});

it('normalises Carbon from()/to() bounds to ISO 8601 UTC Zulu', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()
        ->from(Carbon::parse('2026-01-01 01:00:00', 'Europe/Dublin'))
        ->to(Carbon::parse('2026-02-01 00:00:00', 'UTC'))
        ->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--from=2026-01-01T01:00:00Z')
            ->toContain('--to=2026-02-01T00:00:00Z');

        return true;
    });
});

it('assembles all filters in the upstream --help order regardless of call order', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->makeGaze()->audit('/tmp/audit.sqlite')->query()
        ->onlyRestoreEvents()
        ->whereCollisionVariant('var-2')
        ->whereCollisionFamily('fam-1')
        ->whereAmbiguityReason('no-anchor')
        ->hasAmbiguity()
        ->whereSession('sess-1')
        ->to('2026-02-01T00:00:00Z')
        ->from('2026-01-01T00:00:00Z')
        ->whereDocumentKind('text')
        ->whereAction('tokenize')
        ->whereSource('email.global')
        ->whereClass('email')
        ->execute();

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'audit',
            'query',
            '--audit-db=/tmp/audit.sqlite',
            '--class=email',
            '--source=email.global',
            '--action=tokenize',
            '--document-kind=text',
            '--from=2026-01-01T00:00:00Z',
            '--to=2026-02-01T00:00:00Z',
            '--session=sess-1',
            '--has-ambiguity',
            '--ambiguity-reason=no-anchor',
            '--collision-family=fam-1',
            '--collision-variant=var-2',
            '--restore-events',
        ]);

        return true;
    });
});

it('every filter method is fluent and returns the same QueryBuilder', function () {
    $builder = $this->makeGaze()->audit('/tmp/audit.sqlite')->query();

    expect($builder->whereClass('email'))->toBe($builder)
        ->and($builder->whereSource('s'))->toBe($builder)
        ->and($builder->whereAction('tokenize'))->toBe($builder)
        ->and($builder->whereDocumentKind('text'))->toBe($builder)
        ->and($builder->from('2026-01-01T00:00:00Z'))->toBe($builder)
        ->and($builder->to('2026-02-01T00:00:00Z'))->toBe($builder)
        ->and($builder->whereSession('sess-1'))->toBe($builder)
        ->and($builder->hasAmbiguity())->toBe($builder)
        ->and($builder->whereAmbiguityReason('no-anchor'))->toBe($builder)
        ->and($builder->whereCollisionFamily('fam-1'))->toBe($builder)
        ->and($builder->whereCollisionVariant('var-2'))->toBe($builder);
});

it('parses TSV rows including the upstream header line as the first row', function () {
    Process::fake([
        '*' => Process::result(output: "source\tclass\taction\nemail.global\temail\ttokenize\n"),
    ]);

    $rows = $this->makeGaze()->audit('/tmp/audit.sqlite')->query()->execute();

    expect($rows)->toBe([
        ['source', 'class', 'action'],
        ['email.global', 'email', 'tokenize'],
    ]);
});
