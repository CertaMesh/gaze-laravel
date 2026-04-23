<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Facades;

use Illuminate\Support\Facades\Facade;
use Naoray\GazeLaravel\Gaze as GazeService;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\Testing\FakeGaze;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @method static \Naoray\GazeLaravel\GazeSession clean(string $text)
 * @method static string restore(\Naoray\GazeLaravel\GazeSession $session, string $text)
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
     */
    public static function fake(
        ?\Closure $cleanHandler = null,
        ?\Closure $restoreHandler = null,
    ): FakeGaze {
        $fake = new FakeGaze($cleanHandler, $restoreHandler);
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

    public static function assertNothingCleaned(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->cleanCalls(),
            'Expected Gaze::clean not to be called.',
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
