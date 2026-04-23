<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\Tests\TestCase;

final class GazeTest extends TestCase
{
    public function test_sanitize_returns_session_from_binary_output(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'clean_text' => 'Hello <CUSTOMER_NAME>',
                'session_blob' => 'blob-bytes',
                'metadata' => ['placeholders' => ['<CUSTOMER_NAME>']],
                'warnings' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $gaze = $this->makeGaze();
        $session = $gaze->sanitize('Hello Alice', new Context(customerName: 'Alice'));

        self::assertSame('Hello <CUSTOMER_NAME>', $session->cleanText);
        self::assertSame('blob-bytes', $session->sessionBlob);
        self::assertSame(['<CUSTOMER_NAME>'], $session->placeholders);
        self::assertSame([], $session->warnings);

        Process::assertRan(function ($process): bool {
            $cmd = $process->command;
            $input = $process->input ?? '';

            self::assertIsArray($cmd);
            self::assertSame('sanitize', $cmd[1]);
            self::assertIsString($input);
            $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Hello Alice', $payload['text']);
            self::assertSame(['customer_name' => 'Alice'], $payload['context']);

            return true;
        });
    }

    public function test_sanitize_works_without_context(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'clean_text' => 'foo',
                'session_blob' => 'b',
                'metadata' => ['placeholders' => []],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $session = $this->makeGaze()->sanitize('foo');

        self::assertSame('foo', $session->cleanText);
        self::assertSame([], $session->placeholders);
        self::assertSame([], $session->warnings);

        Process::assertRan(function ($process): bool {
            $input = $process->input ?? '';
            self::assertIsString($input);
            $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('context', $payload);

            return true;
        });
    }

    public function test_restore_returns_text(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'restored_text' => 'Hello Alice',
                'warnings' => ['w'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $restored = $this->makeGaze()->restore('Hello <CUSTOMER_NAME>', 'blob-bytes');

        self::assertSame('Hello Alice', $restored->text);
        self::assertSame(['w'], $restored->warnings);

        Process::assertRan(function ($process): bool {
            self::assertSame('restore', $process->command[1]);
            $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Hello <CUSTOMER_NAME>', $payload['text']);
            self::assertSame('blob-bytes', $payload['session_blob']);

            return true;
        });
    }

    private function makeGaze(): Gaze
    {
        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: '/fake/ghostwriter',
                vendorBinPath: '/nonexistent',
            ),
            process: $this->app->make(ProcessFactory::class),
            timeoutSeconds: 5,
        );
    }
}
