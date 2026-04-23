<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Facades;

use Illuminate\Support\Facades\Facade;
use Naoray\GazeLaravel\Gaze as GazeService;
use Naoray\GazeLaravel\Testing\FakeGaze;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @method static \Naoray\GazeLaravel\GazeSession sanitize(string $text, ?\Naoray\GazeLaravel\Context $context = null)
 * @method static \Naoray\GazeLaravel\RestoredText restore(string $text, string $sessionBlob)
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
     * @param  \Closure(string, ?\Naoray\GazeLaravel\Context): \Naoray\GazeLaravel\GazeSession|null  $sanitizeHandler
     * @param  \Closure(string, string): \Naoray\GazeLaravel\RestoredText|null  $restoreHandler
     */
    public static function fake(
        ?\Closure $sanitizeHandler = null,
        ?\Closure $restoreHandler = null,
    ): FakeGaze {
        $fake = new FakeGaze($sanitizeHandler, $restoreHandler);
        static::swap($fake);

        return $fake;
    }

    public static function assertSanitized(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->sanitizeCalls(),
                'Expected Gaze::sanitize to be called at least once.',
            );

            return;
        }

        foreach ($fake->sanitizeCalls() as $call) {
            if ($call['text'] === $expectedText) {
                PHPUnit::assertTrue(true);

                return;
            }
        }

        PHPUnit::fail("Expected Gaze::sanitize to be called with given text, but it was not.");
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

    public static function assertSanitizeCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->sanitizeCalls(),
            "Expected Gaze::sanitize to be called {$expected} time(s).",
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

    public static function assertNothingSanitized(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->sanitizeCalls(),
            'Expected Gaze::sanitize not to be called.',
        );
    }

    private static function requireFake(): FakeGaze
    {
        $resolved = static::getFacadeRoot();

        if (! $resolved instanceof FakeGaze) {
            PHPUnit::fail(
                'Gaze::fake() has not been called. Call Gaze::fake() before asserting.',
            );
        }

        return $resolved;
    }
}
