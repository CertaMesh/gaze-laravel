<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

class Gaze
{
    public function __construct(
        private readonly BinaryResolver $resolver,
        private readonly ProcessFactory $process,
        private readonly int $timeoutSeconds,
    ) {}

    public function sanitize(string $text, ?Context $context = null): GazeSession
    {
        $payload = ['text' => $text];
        if ($context !== null) {
            $payload['context'] = $context->toArray();
        }

        $result = $this->run('sanitize', $payload, GazeSanitizeFailedException::class);

        /** @var array{clean_text: string, session_blob: string, metadata?: array{placeholders?: list<string>}, warnings?: list<string>} $decoded */
        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new GazeSession(
            cleanText: $decoded['clean_text'],
            sessionBlob: $decoded['session_blob'],
            placeholders: $decoded['metadata']['placeholders'] ?? [],
            warnings: $decoded['warnings'] ?? [],
        );
    }

    public function restore(string $text, string $sessionBlob): RestoredText
    {
        $result = $this->run(
            'restore',
            ['text' => $text, 'session_blob' => $sessionBlob],
            GazeRestoreFailedException::class,
        );

        /** @var array{restored_text: string, warnings?: list<string>} $decoded */
        $decoded = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return new RestoredText(
            text: $decoded['restored_text'],
            warnings: $decoded['warnings'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string<GazeException>  $failureClass
     */
    private function run(string $subcommand, array $payload, string $failureClass): ProcessResult
    {
        $binary = $this->resolver->resolve();
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        try {
            $result = $this->process
                ->newPendingProcess()
                ->timeout($this->timeoutSeconds)
                ->input($json)
                ->run([$binary, $subcommand]);
        } catch (ProcessTimedOutException $e) {
            throw $this->mapTimeout($subcommand, $e);
        }

        if ($result->successful()) {
            return $result;
        }

        throw $this->mapFailure($subcommand, $result, $failureClass);
    }

    private function mapTimeout(string $stage, ProcessTimedOutException $e): GazeTimeoutException
    {
        $stderrHash = hash('sha256', '');
        $exitCode = -1;

        Log::warning("gaze {$stage} failed", [
            'exit_code' => $exitCode,
            'stderr_sha256' => $stderrHash,
            'reason' => 'timeout',
        ]);

        // Intentionally do NOT include $e->getMessage() — the Symfony message
        // may embed the command line, which starts with the resolved binary
        // path. Construct a static message instead.
        unset($e);

        return new GazeTimeoutException(
            "gaze {$stage} timed out (exit={$exitCode}, stderr_sha256={$stderrHash})",
            $exitCode,
            $stderrHash,
        );
    }

    /**
     * @param  class-string<GazeException>  $fallbackClass
     */
    private function mapFailure(string $stage, ProcessResult $result, string $fallbackClass): \Throwable
    {
        $stderr = $result->errorOutput() ?: '';
        $stderrHash = hash('sha256', $stderr);
        $exitCode = $result->exitCode() ?? -1;

        Log::warning("gaze {$stage} failed", [
            'exit_code' => $exitCode,
            'stderr_sha256' => $stderrHash,
        ]);

        // Map known error kinds by best-effort stderr tag inspection.
        // The Rust side's stderr envelope is currently anyhow::Error text; this
        // mapping is opportunistic until the binary emits structured errors.
        // Raw stderr never leaves this function. Only the SHA-256 and exit code
        // escape — see PLAN.md "Stderr rule (load-bearing)".
        if (str_contains($stderr, 'UnknownToken')) {
            return new GazeUnknownTokenException(
                "gaze {$stage} unknown token (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        if (str_contains($stderr, 'BlobExpired')) {
            return new GazeBlobExpiredException(
                "gaze {$stage} blob expired (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        if (str_contains(strtolower($stderr), 'timed out')) {
            return new GazeTimeoutException(
                "gaze {$stage} timed out (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            );
        }

        return new $fallbackClass(
            "gaze {$stage} failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
            $exitCode,
            $stderrHash,
        );
    }
}
