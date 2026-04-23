<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;
use Naoray\GazeLaravel\Tests\TestCase;

final class CanaryCommandTest extends TestCase
{
    public function test_canary_passes_when_sanitize_removes_pii_and_restore_brings_it_back(): void
    {
        $original = 'Hi, this is Krishan Koenig (k@example.com / +353 1 234 5678). Please cancel order ORD-CANARY-ZZ.';
        $clean = 'Hi, this is <CUSTOMER_NAME> (<CUSTOMER_EMAIL> / <CUSTOMER_PHONE>). Please cancel order ORD-CANARY-ZZ.';

        $this->bindScriptedGaze(
            sanitizeResult: new GazeSession(
                cleanText: $clean,
                sessionBlob: 'blob',
                placeholders: ['<CUSTOMER_NAME>', '<CUSTOMER_EMAIL>', '<CUSTOMER_PHONE>'],
                warnings: [],
            ),
            restoreResult: new RestoredText(text: $original, warnings: []),
        );

        $this->artisan('gaze:canary')
            ->assertExitCode(0)
            ->expectsOutputToContain('[1/3] sanitize')
            ->expectsOutputToContain('[2/3] marker-absent')
            ->expectsOutputToContain('[3/3] restore+marker')
            ->expectsOutputToContain('PASS');
    }

    public function test_canary_fails_when_pii_leaks_into_clean_text(): void
    {
        $this->bindScriptedGaze(
            sanitizeResult: new GazeSession(
                cleanText: 'Hi, Krishan Koenig is leaked',
                sessionBlob: 'blob',
                placeholders: [],
                warnings: [],
            ),
        );

        $this->artisan('gaze:canary')
            ->assertExitCode(1)
            ->expectsOutputToContain('leaked into clean text');
    }

    public function test_canary_fails_when_restore_drops_pii(): void
    {
        $this->bindScriptedGaze(
            sanitizeResult: new GazeSession(
                cleanText: 'Hi, this is <CUSTOMER_NAME> (<CUSTOMER_EMAIL> / <CUSTOMER_PHONE>).',
                sessionBlob: 'blob',
                placeholders: ['<CUSTOMER_NAME>', '<CUSTOMER_EMAIL>', '<CUSTOMER_PHONE>'],
                warnings: [],
            ),
            restoreResult: new RestoredText(text: 'Hi, this is someone else.', warnings: []),
        );

        $this->artisan('gaze:canary')
            ->assertExitCode(1)
            ->expectsOutputToContain('missing after restore');
    }

    private function bindScriptedGaze(
        GazeSession $sanitizeResult,
        ?RestoredText $restoreResult = null,
    ): void {
        $stub = new class($sanitizeResult, $restoreResult) extends Gaze {
            public function __construct(
                private readonly GazeSession $sanitize,
                private readonly ?RestoredText $restore,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function sanitize(string $text, ?Context $context = null): GazeSession
            {
                return $this->sanitize;
            }

            public function restore(string $text, string $sessionBlob): RestoredText
            {
                return $this->restore ?? new RestoredText(text: $text, warnings: []);
            }
        };

        $this->app->instance(Gaze::class, $stub);
    }
}
