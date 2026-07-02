<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\GazeSession;

/**
 * Test double for the `Gaze` service. Implements `Contracts\Gaze` directly
 * (it does NOT extend the concrete, process-invoking `CertaMesh\Gaze\Gaze`)
 * so its surface is exactly the public contract — no inherited internals
 * that would fatal on an uninitialized constructor state.
 */
final class FakeGaze implements GazeContract
{
    private const TOKEN_PATTERN = '/<(?:Email|Name|Location|Organization)_\d+>|<Custom:[a-z0-9_]*_\d+>|\b(?:email|name|location|organization)_\d+\b|\bcustom:[a-z0-9_]*_\d+\b|\bemail\d+@example\.test\b|<[A-Z][a-zA-Z]+_\d+>|<[a-z][a-z_]+_\d+>|\b[A-Z][a-zA-Z]+_\d+\b|\b[a-z][a-z_]+_\d+\b/';

    /** @var list<array{text: string, threshold: float|null}> */
    private array $cleanCalls = [];

    /** @var list<array{text: string}> */
    private array $maskCalls = [];

    /** @var list<array{text: string, clean_text: string}> */
    private array $restoreCalls = [];

    private readonly FakeAuditService $auditService;

    private readonly FakeDaemonManager $daemonManager;

    /**
     * @param  \Closure(string, ?float): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     * @param  \Closure(string, bool): AuditPurgeResult|null  $auditPurgeHandler
     * @param  \Closure(string, string): CleanResponse|null  $daemonCleanHandler
     */
    public function __construct(
        private readonly ?\Closure $cleanHandler = null,
        private readonly ?\Closure $restoreHandler = null,
        ?\Closure $auditPurgeHandler = null,
        ?\Closure $daemonCleanHandler = null,
    ) {
        $this->auditService = new FakeAuditService($auditPurgeHandler);
        $this->daemonManager = new FakeDaemonManager($daemonCleanHandler);
    }

    public function daemon(): FakeDaemonManager
    {
        return $this->daemonManager;
    }

    public function clean(string $text, ?float $threshold = null): GazeSession
    {
        $this->cleanCalls[] = ['text' => $text, 'threshold' => $threshold];

        if ($this->cleanHandler !== null) {
            // Always invoked with both arguments. PHP user-land closures
            // silently ignore surplus arguments, so pre-existing handlers
            // typed (string $text) keep working unchanged, while handlers
            // declaring (string $text, ?float $threshold) can branch on the
            // per-call threshold exactly like the real Gaze::clean() does.
            return ($this->cleanHandler)($text, $threshold);
        }

        return new GazeSession(
            cleanText: $this->fakeCleanText($text),
            ciphertext: EncryptedBlob::wrap(base64_encode(json_encode(['text' => $text], JSON_THROW_ON_ERROR))),
            detections: 1,
        );
    }

    /**
     * Mirror the one-way mask() helper: record the call, then run the same
     * clean + token-map sweep composition the real Gaze::mask() performs.
     * It re-enters this fake's clean() — so a $cleanHandler returning
     * entries drives the masking just as the real binary's inventory would,
     * with no process invocation.
     *
     * @param  (callable(Entry): string)|null  $replace
     */
    public function mask(string $text, ?callable $replace = null): string
    {
        $this->maskCalls[] = ['text' => $text];

        $session = $this->clean($text);

        $masked = $session->cleanText;
        foreach ($session->entries as $entry) {
            $label = $replace !== null ? $replace($entry) : '['.$entry->class.']';
            $masked = str_replace($entry->token, $label, $masked);
        }

        return $masked;
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
     * @return list<array{text: string, threshold: float|null}>
     */
    public function cleanCalls(): array
    {
        return $this->cleanCalls;
    }

    /**
     * @return list<array{text: string}>
     */
    public function maskCalls(): array
    {
        return $this->maskCalls;
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
