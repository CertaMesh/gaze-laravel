<?php

declare(strict_types=1);

use Carbon\Carbon;
use Naoray\GazeLaravel\Audit\AuditPurgeResult;
use Naoray\GazeLaravel\Facades\Gaze;
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
