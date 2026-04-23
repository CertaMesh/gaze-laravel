<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Integration;

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Tests\TestCase;

final class SanitizeRoundTripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $binary = getenv('GAZE_BINARY');
        if (! is_string($binary) || $binary === '') {
            $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
        }

        $this->app['config']->set('gaze.binary', $binary);
    }

    public function test_sanitize_then_restore_recovers_original_text(): void
    {
        $original = 'Hi Alice (alice@example.com), please confirm.';

        $gaze = $this->app->make(Gaze::class);

        $session = $gaze->sanitize(
            $original,
            new Context(customerName: 'Alice', customerEmail: 'alice@example.com'),
        );

        self::assertStringNotContainsString('Alice', $session->cleanText);
        self::assertStringNotContainsString('alice@example.com', $session->cleanText);

        $restored = $gaze->restore($session->cleanText, $session->sessionBlob);

        self::assertStringContainsString('Alice', $restored->text);
        self::assertStringContainsString('alice@example.com', $restored->text);
    }
}
