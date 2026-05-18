<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Testing;

use Naoray\GazeLaravel\Audit\AuditPurgeResult;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\Gaze;
use Naoray\GazeLaravel\GazeSession;

final class FakeGaze extends Gaze
{
    private const TOKEN_PATTERN = '/<(?:Email|Name|Location|Organization)_\d+>|<Custom:[a-z0-9_]*_\d+>|\b(?:email|name|location|organization)_\d+\b|\bcustom:[a-z0-9_]*_\d+\b|\bemail\d+@example\.test\b|<[A-Z][a-zA-Z]+_\d+>|<[a-z][a-z_]+_\d+>|\b[A-Z][a-zA-Z]+_\d+\b|\b[a-z][a-z_]+_\d+\b/';

    /** @var list<array{text: string}> */
    private array $cleanCalls = [];

    /** @var list<array{text: string, clean_text: string}> */
    private array $restoreCalls = [];

    private readonly FakeAuditService $auditService;

    private readonly FakeDaemonManager $daemonManager;

    /**
     * @param  \Closure(string): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     * @param  \Closure(string, bool): AuditPurgeResult|null  $auditPurgeHandler
     * @param  \Closure(string, string): \Naoray\GazeLaravel\Daemon\CleanResponse|null  $daemonCleanHandler
     */
    public function __construct(
        private readonly ?\Closure $cleanHandler = null,
        private readonly ?\Closure $restoreHandler = null,
        ?\Closure $auditPurgeHandler = null,
        ?\Closure $daemonCleanHandler = null,
    ) {
        // Deliberately skip parent constructor — fake never invokes process.
        $this->auditService = new FakeAuditService($auditPurgeHandler);
        $this->daemonManager = new FakeDaemonManager($daemonCleanHandler);
    }

    public function daemon(): FakeDaemonManager
    {
        return $this->daemonManager;
    }

    public function clean(string $text): GazeSession
    {
        $this->cleanCalls[] = ['text' => $text];

        if ($this->cleanHandler !== null) {
            return ($this->cleanHandler)($text);
        }

        return new GazeSession(
            cleanText: $this->fakeCleanText($text),
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

    public function audit(?string $auditDbPath = null): FakeAuditService
    {
        return $this->auditService;
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

    private function fakeCleanText(string $text): string
    {
        $cleanText = preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $match): string {
                $token = $match[0];

                if (preg_match('/^email\d+@example\.test$/', $token) === 1) {
                    return 'email1@example.test';
                }

                if (str_starts_with($token, '<Custom:')) {
                    return '<Custom:order_id_1>';
                }

                if (str_starts_with($token, 'custom:')) {
                    return 'custom:order_id_1';
                }

                if (str_starts_with($token, '<')) {
                    return ctype_lower($token[1]) ? '<name_1>' : '<Name_1>';
                }

                return ctype_lower($token[0]) ? 'name_1' : 'Name_1';
            },
            $text,
        );

        if (! is_string($cleanText) || $cleanText === $text) {
            return str_replace('Alice', 'Name_1', $text);
        }

        return $cleanText;
    }
}
