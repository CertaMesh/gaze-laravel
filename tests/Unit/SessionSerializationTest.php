<?php

declare(strict_types=1);

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\LeakReport;
use CertaMesh\Gaze\LeakSuspect;

/**
 * serialize() of a LeakReport as the v0.15.0 class wrote it: seven properties,
 * no actsOnSuspects / unactionableSubwordCount. Produced by the released
 * src/LeakReport.php + src/LeakSuspect.php at tag v0.15.0 (loaded on their
 * own, no autoloader) from the captured 0.15.1 Nym report
 * (`Invoice date 1971-05-30, plate B-MW 1234`, synthetic).
 */
const LEAK_REPORT_SERIALIZED_V0150 = <<<'TXT'
O:25:"CertaMesh\Gaze\LeakReport":7:{s:12:"suspectCount";i:2;s:14:"uncoveredCount";i:2;s:17:"partialBleedCount";i:0;s:18:"classMismatchCount";i:0;s:18:"localeSkippedCount";i:0;s:8:"suspects";a:2:{i:0;O:26:"CertaMesh\Gaze\LeakSuspect":8:{s:11:"safetyNetId";s:14:"nym-small-int8";s:8:"rawLabel";s:18:"DATE_OF_BIRTH>=0.9";s:11:"mappedClass";s:11:"Custom:date";s:8:"leakKind";s:9:"uncovered";s:13:"pipelineClass";N;s:7:"spanLen";i:10;s:9:"fieldPath";N;s:5:"score";d:0.9992361;}i:1;O:26:"CertaMesh\Gaze\LeakSuspect":8:{s:11:"safetyNetId";s:14:"nym-small-int8";s:8:"rawLabel";s:18:"LICENSE_PLATE>=0.5";s:11:"mappedClass";s:20:"Custom:license_plate";s:8:"leakKind";s:9:"uncovered";s:13:"pipelineClass";N;s:7:"spanLen";i:9;s:9:"fieldPath";N;s:5:"score";d:0.99993986;}}s:10:"replayHash";N;}
TXT;

it('does not serialize plaintext session blobs into the payload', function () {
    $session = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::wrap('alice@example.com'),
        detections: 1,
    );

    $serialized = serialize($session);

    expect($serialized)->not->toContain('alice@example.com')
        ->and(unserialize($serialized))
        ->toBeInstanceOf(GazeSession::class);
});

it('unserializes a LeakReport a v0.15.x release serialized, reading it as v0.15.x did', function () {
    $report = unserialize(LEAK_REPORT_SERIALIZED_V0150);

    // No decision was recorded then: observe semantics, red for the two
    // flagged spans — never a green the payload cannot back.
    expect($report)->toBeInstanceOf(LeakReport::class)
        ->and($report->suspectCount)->toBe(2)
        ->and($report->uncoveredCount)->toBe(2)
        ->and($report->suspects)->toHaveCount(2)
        ->and($report->suspects[0])->toBeInstanceOf(LeakSuspect::class)
        ->and($report->suspects[1]->mappedClass)->toBe('Custom:license_plate')
        ->and($report->replayHash)->toBeNull()
        ->and($report->actsOnSuspects)->toBeFalse()
        ->and($report->unactionableSubwordCount)->toBe(0)
        ->and($report->coverageState())->toBe(CoverageState::Suspect)
        ->and($report->hasResolvedSuspects())->toBeFalse();
});

it('unserializes a GazeSession queued by a v0.15.x worker', function () {
    // The queued-job path docs/explanation/blob-lifecycle.md permits: the
    // session as v0.15.x serialized it, its LeakReport in the old shape.
    $current = LeakReport::fromArray(['stats' => ['suspect_count' => 2, 'uncovered_count' => 2]], actsOnSuspects: true);
    $payload = str_replace(
        serialize($current),
        LEAK_REPORT_SERIALIZED_V0150,
        serialize(new GazeSession(
            cleanText: 'Invoice date <h:Custom:date_1>, plate <h:Custom:license_plate_1>',
            ciphertext: EncryptedBlob::wrap('session-blob'),
            detections: 0,
            leakReport: $current,
        )),
    );

    expect($payload)->toContain(LEAK_REPORT_SERIALIZED_V0150);

    $session = unserialize($payload);

    expect($session)->toBeInstanceOf(GazeSession::class)
        ->and($session->coverageState())->toBe(CoverageState::Suspect)
        ->and($session->hasSuspectedLeak())->toBeTrue()
        ->and($session->ciphertext->decryptedBlob())->toBe('session-blob');
});

it('round-trips a current LeakReport through serialize with every field', function () {
    $report = new LeakReport(
        suspectCount: 1,
        uncoveredCount: 1,
        partialBleedCount: 0,
        classMismatchCount: 0,
        localeSkippedCount: 0,
        suspects: [new LeakSuspect('nym-small-int8', 'LICENSE_PLATE>=0.5', 'Custom:license_plate', 'uncovered', null, 9, null, 0.99)],
        replayHash: 'abc123',
        actsOnSuspects: true,
        unactionableSubwordCount: 1,
    );

    $woken = unserialize(serialize($report));

    expect($woken)->toEqual($report)
        ->and($woken->coverageState())->toBe(CoverageState::Suspect);
});

it('refuses a LeakReport payload without its counts instead of reading it green', function () {
    // suspectCount removed: defaulting it to 0 would turn red into green.
    $truncated = str_replace(
        ['O:25:"CertaMesh\Gaze\LeakReport":7:', 's:12:"suspectCount";i:2;'],
        ['O:25:"CertaMesh\Gaze\LeakReport":6:', ''],
        LEAK_REPORT_SERIALIZED_V0150,
    );

    expect(fn () => unserialize($truncated))
        ->toThrow(UnexpectedValueException::class, 'suspectCount');
});
