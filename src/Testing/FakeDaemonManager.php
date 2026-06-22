<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Daemon\DaemonSession;

/**
 * Test double for the `Gaze::daemon()` chain. Records every call and
 * returns a deterministic `CleanResponse` so assertions can run without
 * a real `gaze daemon` subprocess.
 *
 * Extends `DaemonManager` so `FakeGaze::daemon()` honors the parent
 * `Gaze::daemon(): DaemonManager` return type. The parent constructor
 * is bypassed since the fake never resolves a real client.
 *
 * Adopter usage:
 *
 *     Gaze::fake();
 *     Gaze::daemon()->session('agent-a')->clean('hi');
 *     Gaze::assertDaemonCleaned('agent-a');
 */
final class FakeDaemonManager extends DaemonManager
{
    /** @var list<array{session_id: string, text: string}> */
    private array $calls = [];

    /** @var array<string, DaemonSession> */
    private array $sessionDoubles = [];

    public function __construct(
        private readonly ?\Closure $cleanHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes a real client.
    }

    public function session(string $id): DaemonSession
    {
        if (! isset($this->sessionDoubles[$id])) {
            $this->sessionDoubles[$id] = new FakeDaemonSession($id, $this);
        }

        return $this->sessionDoubles[$id];
    }

    public function clean(string $sessionId, string $text): CleanResponse
    {
        $this->calls[] = ['session_id' => $sessionId, 'text' => $text];

        if ($this->cleanHandler !== null) {
            return ($this->cleanHandler)($sessionId, $text);
        }

        return new CleanResponse(
            sessionId: $sessionId,
            cleanText: $this->fakeCleanText($text),
            manifest: [],
            tokens: [],
            raw: [
                'session_id' => $sessionId,
                'clean_text' => $this->fakeCleanText($text),
                'manifest' => [],
                'tokens' => [],
            ],
        );
    }

    /**
     * @return list<array{session_id: string, text: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    private function fakeCleanText(string $text): string
    {
        // Mirror FakeGaze::fakeCleanText simple-token semantics so adopter
        // tests that read clean_text get a predictable masked shape.
        return preg_replace(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            '<Email_1>',
            $text,
        ) ?? $text;
    }
}
