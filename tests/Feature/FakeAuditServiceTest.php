<?php

declare(strict_types=1);

use Carbon\Carbon;
use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Facades\Gaze;
use PHPUnit\Framework\AssertionFailedError;

it('records audit purge calls and supports Carbon matcher assertions', function () {
    Gaze::fake();

    Gaze::audit()->purge()->before(Carbon::parse('2026-02-03 04:05:06', 'UTC'))->dryRun();

    Gaze::assertAuditPurged(Carbon::parse('2026-02-03T04:05:06Z'));

    $calls = Gaze::getFacadeRoot()->audit()->purgeCalls();

    expect($calls)->toBe([
        ['before' => '2026-02-03T04:05:06Z', 'dry_run' => true],
    ]);
});

it('asserts any audit purge call when no timestamp is provided', function () {
    Gaze::fake();

    Gaze::audit()->purge()->before('2026-01-01T00:00:00Z')->execute();

    Gaze::assertAuditPurged();
});

it('assertNothingAudited fails after an audit purge call', function () {
    Gaze::fake();

    Gaze::audit()->purge()->before('2026-01-01T00:00:00Z')->execute();

    expect(fn () => Gaze::assertNothingAudited())
        ->toThrow(AssertionFailedError::class);
});

it('assertNothingAudited passes when no audit verb was called', function () {
    Gaze::fake();

    Gaze::clean('Hello Alice');

    Gaze::assertNothingAudited();
});

it('asserts audit purge call counts', function () {
    Gaze::fake();

    Gaze::audit()->purge()->before('2026-01-01T00:00:00Z')->dryRun();
    Gaze::audit()->purge()->before('2026-01-02T00:00:00Z')->execute();

    Gaze::assertAuditPurgeCount(2);

    expect(fn () => Gaze::assertAuditPurgeCount(99))
        ->toThrow(AssertionFailedError::class);
});

it('records audit export calls with output path, format, and applied filters', function () {
    Gaze::fake();

    Gaze::audit()->query()
        ->whereClass('email')
        ->from('2026-01-01T00:00:00Z')
        ->onlyRestoreEvents()
        ->export('/tmp/export.jsonl');

    Gaze::assertAuditExported('/tmp/export.jsonl');
    Gaze::assertAuditExported();

    expect(Gaze::getFacadeRoot()->audit()->exportCalls())->toBe([
        [
            'output' => '/tmp/export.jsonl',
            'format' => 'jsonl',
            'filters' => [
                '--class' => 'email',
                '--from' => '2026-01-01T00:00:00Z',
                '--restore-events' => true,
            ],
        ],
    ]);
});

it('assertAuditExported fails for a path that was never exported', function () {
    Gaze::fake();

    Gaze::audit()->query()->export('/tmp/export.jsonl');

    expect(fn () => Gaze::assertAuditExported('/tmp/other.jsonl'))
        ->toThrow(AssertionFailedError::class);
});

it('lets the fake export handler customize the AuditExportResult', function () {
    Gaze::fake(
        auditExportHandler: fn (?string $output, string $format): AuditExportResult => new AuditExportResult(
            format: $format,
            path: $output,
            rawOutput: '{"class":"email"}'."\n",
        ),
    );

    $result = Gaze::audit()->query()->export();

    expect($result->rowCount())->toBe(1)
        ->and($result->rows())->toBe([['class' => 'email']]);
});

it('records safety-net query calls with applied filters', function () {
    Gaze::fake();

    $rows = Gaze::audit()->safetyNetQuery()
        ->whereLeakKind('fresh')
        ->whereMappedClass('email')
        ->execute();

    expect($rows)->toBe([])
        ->and(Gaze::getFacadeRoot()->audit()->safetyNetQueryCalls())->toBe([
            ['filters' => ['--leak-kind' => 'fresh', '--mapped-class' => 'email']],
        ]);
});

it('assertNothingAudited fails after an export or a safety-net query', function () {
    Gaze::fake();
    Gaze::audit()->query()->export();
    expect(fn () => Gaze::assertNothingAudited())->toThrow(AssertionFailedError::class);

    Gaze::fake();
    Gaze::audit()->safetyNetQuery()->execute();
    expect(fn () => Gaze::assertNothingAudited())->toThrow(AssertionFailedError::class);
});

it('FakeQueryBuilder records applied filters for assertions', function () {
    $fake = Gaze::fake();

    $builder = $fake->audit()->query()
        ->whereClass('email')
        ->whereSource('email.global')
        ->whereAction('tokenize')
        ->whereDocumentKind('text')
        ->from('2026-01-01T00:00:00Z')
        ->to('2026-02-01T00:00:00Z')
        ->whereSession('sess-1')
        ->hasAmbiguity()
        ->whereAmbiguityReason('no-anchor')
        ->whereCollisionFamily('fam-1')
        ->whereCollisionVariant('var-2')
        ->onlyRestoreEvents();

    expect($builder->appliedFilters())->toBe([
        '--class' => 'email',
        '--source' => 'email.global',
        '--action' => 'tokenize',
        '--document-kind' => 'text',
        '--from' => '2026-01-01T00:00:00Z',
        '--to' => '2026-02-01T00:00:00Z',
        '--session' => 'sess-1',
        '--has-ambiguity' => true,
        '--ambiguity-reason' => 'no-anchor',
        '--collision-family' => 'fam-1',
        '--collision-variant' => 'var-2',
        '--restore-events' => true,
    ])->and($builder->wasRestrictedToRestoreEvents())->toBeTrue();
});

it('lets the fake purge handler customize the AuditPurgeResult', function () {
    Gaze::fake(
        auditPurgeHandler: fn (string $before, bool $dryRun): AuditPurgeResult => new AuditPurgeResult(
            rawOutput: "custom {$before} ".($dryRun ? 'dry' : 'live'),
            count: 42,
        ),
    );

    $result = Gaze::audit()->purge()->before('2026-01-01T00:00:00Z')->dryRun();

    expect($result->rawOutput)->toBe('custom 2026-01-01T00:00:00Z dry')
        ->and($result->count)->toBe(42);
});
