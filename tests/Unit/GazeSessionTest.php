<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Unit;

use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use PHPUnit\Framework\TestCase;

final class GazeSessionTest extends TestCase
{
    public function test_gaze_session_exposes_all_fields(): void
    {
        $session = new GazeSession(
            cleanText: 'Hello <CUSTOMER_NAME>',
            sessionBlob: 'blob-bytes',
            placeholders: ['<CUSTOMER_NAME>'],
            warnings: [],
        );

        self::assertSame('Hello <CUSTOMER_NAME>', $session->cleanText);
        self::assertSame('blob-bytes', $session->sessionBlob);
        self::assertSame(['<CUSTOMER_NAME>'], $session->placeholders);
        self::assertSame([], $session->warnings);
    }

    public function test_restored_text_exposes_fields(): void
    {
        $restored = new RestoredText(text: 'Hello Alice', warnings: ['w1']);

        self::assertSame('Hello Alice', $restored->text);
        self::assertSame(['w1'], $restored->warnings);
    }
}
