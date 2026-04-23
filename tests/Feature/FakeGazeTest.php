<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use Naoray\GazeLaravel\Testing\FakeGaze;
use Naoray\GazeLaravel\Tests\TestCase;

final class FakeGazeTest extends TestCase
{
    public function test_fake_satisfies_real_type_hints(): void
    {
        $fake = new FakeGaze();
        $this->app->instance(Gaze::class, $fake);

        $resolved = $this->app->make(Gaze::class);

        self::assertInstanceOf(Gaze::class, $resolved);
        self::assertSame($fake, $resolved);
    }

    public function test_default_sanitize_swaps_customer_name_and_records_call(): void
    {
        $fake = new FakeGaze();

        $session = $fake->sanitize('Hello Alice', new Context(customerName: 'Alice'));

        self::assertSame('Hello <CUSTOMER_NAME>', $session->cleanText);
        self::assertSame(['<CUSTOMER_NAME>'], $session->placeholders);
        self::assertCount(1, $fake->sanitizeCalls());
        self::assertSame('Hello Alice', $fake->sanitizeCalls()[0]['text']);
    }

    public function test_default_restore_round_trips(): void
    {
        $fake = new FakeGaze();

        $session = $fake->sanitize('Hello Alice', new Context(customerName: 'Alice'));
        $restored = $fake->restore($session->cleanText, $session->sessionBlob);

        self::assertSame('Hello Alice', $restored->text);
        self::assertCount(1, $fake->restoreCalls());
    }

    public function test_custom_handlers_are_invoked(): void
    {
        $fake = new FakeGaze(
            sanitizeHandler: fn (string $text, ?Context $context) => new GazeSession(
                cleanText: '[CLEAN]',
                sessionBlob: 'b',
                placeholders: [],
                warnings: ['w'],
            ),
            restoreHandler: fn (string $text, string $blob) => new RestoredText(
                text: '[RESTORED]',
                warnings: [],
            ),
        );

        self::assertSame('[CLEAN]', $fake->sanitize('anything')->cleanText);
        self::assertSame('[RESTORED]', $fake->restore('x', 'y')->text);
    }
}
