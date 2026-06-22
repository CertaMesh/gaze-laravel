<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Facades;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Gaze as GazeService;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\Testing\FakeGaze;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @method static \CertaMesh\Gaze\GazeSession clean(string $text)
 * @method static string restore(\CertaMesh\Gaze\GazeSession $session, string $text)
 * @method static \CertaMesh\Gaze\Audit\AuditService audit(?string $auditDbPath = null)
 * @method static \CertaMesh\Gaze\Daemon\DaemonManager daemon()
 *
 * @see GazeService
 */
final class Gaze extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GazeService::class;
    }

    /**
     * Swap the bound Gaze service for a FakeGaze and return it so tests can
     * chain assertions. Mirrors Laravel's Queue::fake() / Mail::fake() idiom.
     *
     * @param  \Closure(string): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     * @param  \Closure(string, bool): AuditPurgeResult|null  $auditPurgeHandler
     * @param  \Closure(string, string): CleanResponse|null  $daemonCleanHandler
     */
    public static function fake(
        ?\Closure $cleanHandler = null,
        ?\Closure $restoreHandler = null,
        ?\Closure $auditPurgeHandler = null,
        ?\Closure $daemonCleanHandler = null,
    ): FakeGaze {
        $fake = new FakeGaze($cleanHandler, $restoreHandler, $auditPurgeHandler, $daemonCleanHandler);
        self::swap($fake);

        return $fake;
    }

    public static function assertCleaned(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->cleanCalls(),
                'Expected Gaze::clean to be called at least once.',
            );

            return;
        }

        foreach ($fake->cleanCalls() as $call) {
            if ($call['text'] === $expectedText) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail('Expected Gaze::clean to be called with given text, but it was not.');
    }

    public static function assertRestored(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->restoreCalls(),
                'Expected Gaze::restore to be called at least once.',
            );

            return;
        }

        foreach ($fake->restoreCalls() as $call) {
            if ($call['text'] === $expectedText) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail('Expected Gaze::restore to be called with given text, but it was not.');
    }

    public static function assertCleanCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->cleanCalls(),
            "Expected Gaze::clean to be called {$expected} time(s).",
        );
    }

    public static function assertRestoreCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->restoreCalls(),
            "Expected Gaze::restore to be called {$expected} time(s).",
        );
    }

    public static function assertAuditPurged(?CarbonInterface $before = null): void
    {
        $fake = self::requireFake();
        $calls = $fake->audit()->purgeCalls();

        if ($before === null) {
            PHPUnit::assertNotEmpty(
                $calls,
                'Expected Gaze::audit()->purge() to be called at least once.',
            );

            return;
        }

        $expected = $before->utc()->toIso8601ZuluString();

        foreach ($calls as $call) {
            if ($call['before'] === $expected) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail('Expected Gaze::audit()->purge() to be called with given before timestamp, but it was not.');
    }

    public static function assertAuditPurgeCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->audit()->purgeCalls(),
            "Expected Gaze::audit()->purge() to be called {$expected} time(s).",
        );
    }

    public static function assertNothingCleaned(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->cleanCalls(),
            'Expected Gaze::clean not to be called.',
        );
    }

    public static function assertDaemonCleaned(?string $sessionId = null, ?string $expectedText = null): void
    {
        $fake = self::requireFake();
        $calls = $fake->daemon()->calls();

        if ($sessionId === null && $expectedText === null) {
            PHPUnit::assertNotEmpty(
                $calls,
                'Expected Gaze::daemon()->clean() to be called at least once.',
            );

            return;
        }

        foreach ($calls as $call) {
            $sessionMatch = $sessionId === null || $call['session_id'] === $sessionId;
            $textMatch = $expectedText === null || $call['text'] === $expectedText;
            if ($sessionMatch && $textMatch) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        $criteria = $sessionId !== null ? "session_id={$sessionId}" : 'any session';
        if ($expectedText !== null) {
            $criteria .= " with text={$expectedText}";
        }
        PHPUnit::fail("Expected Gaze::daemon()->clean() to be called for {$criteria}, but it was not.");
    }

    public static function assertDaemonCleanCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->daemon()->calls(),
            "Expected Gaze::daemon()->clean() to be called {$expected} time(s).",
        );
    }

    public static function assertNothingDaemonCleaned(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->daemon()->calls(),
            'Expected Gaze::daemon()->clean() not to be called.',
        );
    }

    public static function assertNothingAudited(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->audit()->purgeCalls(),
            'Expected Gaze audit verbs not to be called.',
        );
    }

    private static function requireFake(): FakeGaze
    {
        $resolved = self::getFacadeRoot();

        if (! $resolved instanceof FakeGaze) {
            PHPUnit::fail(
                'Gaze::fake() has not been called. Call Gaze::fake() before asserting.',
            );
        }

        return $resolved;
    }
}
