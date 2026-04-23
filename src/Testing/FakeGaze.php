<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Testing;

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;
use Naoray\GazeLaravel\RestoredText;

final class FakeGaze extends Gaze
{
    /** @var list<array{text: string, context: ?Context}> */
    private array $sanitizeCalls = [];

    /** @var list<array{text: string, sessionBlob: string}> */
    private array $restoreCalls = [];

    /**
     * @param  \Closure(string, ?Context): GazeSession|null  $sanitizeHandler
     * @param  \Closure(string, string): RestoredText|null  $restoreHandler
     */
    public function __construct(
        private readonly ?\Closure $sanitizeHandler = null,
        private readonly ?\Closure $restoreHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
    }

    public function sanitize(string $text, ?Context $context = null): GazeSession
    {
        $this->sanitizeCalls[] = ['text' => $text, 'context' => $context];

        if ($this->sanitizeHandler !== null) {
            return ($this->sanitizeHandler)($text, $context);
        }

        $name = $context?->customerName ?? '';

        return new GazeSession(
            cleanText: $name !== '' ? str_replace($name, '<CUSTOMER_NAME>', $text) : $text,
            sessionBlob: base64_encode(json_encode(['customer_name' => $name], JSON_THROW_ON_ERROR)),
            placeholders: $name !== '' ? ['<CUSTOMER_NAME>'] : [],
            warnings: [],
        );
    }

    public function restore(string $text, string $sessionBlob): RestoredText
    {
        $this->restoreCalls[] = ['text' => $text, 'sessionBlob' => $sessionBlob];

        if ($this->restoreHandler !== null) {
            return ($this->restoreHandler)($text, $sessionBlob);
        }

        /** @var array{customer_name?: string}|null $map */
        $map = json_decode((string) base64_decode($sessionBlob, true), true);
        $name = is_array($map) ? ($map['customer_name'] ?? '') : '';

        return new RestoredText(
            text: $name !== '' ? str_replace('<CUSTOMER_NAME>', $name, $text) : $text,
            warnings: [],
        );
    }

    /**
     * @return list<array{text: string, context: ?Context}>
     */
    public function sanitizeCalls(): array
    {
        return $this->sanitizeCalls;
    }

    /**
     * @return list<array{text: string, sessionBlob: string}>
     */
    public function restoreCalls(): array
    {
        return $this->restoreCalls;
    }
}
