<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Testing;

use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;

final class FakeGaze extends Gaze
{
    /** @var list<array{text: string}> */
    private array $cleanCalls = [];

    /** @var list<array{text: string, clean_text: string}> */
    private array $restoreCalls = [];

    /**
     * @param  \Closure(string): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     */
    public function __construct(
        private readonly ?\Closure $cleanHandler = null,
        private readonly ?\Closure $restoreHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
    }

    public function clean(string $text): GazeSession
    {
        $this->cleanCalls[] = ['text' => $text];

        if ($this->cleanHandler !== null) {
            return ($this->cleanHandler)($text);
        }

        return new GazeSession(
            cleanText: preg_replace('/[A-Z][a-z]+_\d+/', 'Name_1', $text) ?? str_replace('Alice', 'Name_1', $text),
            ciphertext: EncryptedBlob::wrap(base64_encode(json_encode(['text' => $text], JSON_THROW_ON_ERROR))),
            detections: 1,
        );
    }

    public function restore(GazeSession $session, string $text): string
    {
        $this->restoreCalls[] = ['text' => $text, 'clean_text' => $session->cleanText];

        if ($this->restoreHandler !== null) {
            return ($this->restoreHandler)($session, $text);
        }

        /** @var array{text?: string}|null $map */
        $map = json_decode((string) base64_decode($session->ciphertext->decryptedBlob(), true), true);

        return is_array($map) ? (string) ($map['text'] ?? $text) : $text;
    }

    /**
     * @return list<array{text: string}>
     */
    public function cleanCalls(): array
    {
        return $this->cleanCalls;
    }

    /**
     * @return list<array{text: string, clean_text: string}>
     */
    public function restoreCalls(): array
    {
        return $this->restoreCalls;
    }
}
