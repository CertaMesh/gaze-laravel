<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;
use Naoray\GazeLaravel\Audit\AuditService;
use Naoray\GazeLaravel\Exceptions\GazeAuditPurgeIso8601Exception;
use Naoray\GazeLaravel\Exceptions\GazeBlobExpiredException;
use Naoray\GazeLaravel\Exceptions\GazeEmptyInputException;
use Naoray\GazeLaravel\Exceptions\GazeException;
use Naoray\GazeLaravel\Exceptions\GazeInputTooLargeException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidBlobVersionException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidEncodingException;
use Naoray\GazeLaravel\Exceptions\GazeInvalidSignatureException;
use Naoray\GazeLaravel\Exceptions\GazeIoException;
use Naoray\GazeLaravel\Exceptions\GazePipelineException;
use Naoray\GazeLaravel\Exceptions\GazePolicyConfigDetailException;
use Naoray\GazeLaravel\Exceptions\GazePolicyConfigException;
use Naoray\GazeLaravel\Exceptions\GazePolicyOpenException;
use Naoray\GazeLaravel\Exceptions\GazeResponseDecodeException;
use Naoray\GazeLaravel\Exceptions\GazeSigPipeException;
use Naoray\GazeLaravel\Exceptions\GazeStdinParseException;
use Naoray\GazeLaravel\Exceptions\GazeTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeUnknownTokenException;

class Gaze
{
    private const DEFAULT_MAX_BYTES = 10485760;

    public function __construct(
        private readonly BinaryResolver $resolver,
        private readonly ProcessFactory $process,
        private readonly int $timeoutSeconds,
        private readonly Container $container,
        private readonly ?string $policyPath = null,
        private readonly ?int $maxBytes = null,
        private readonly ?int $sessionTtlSeconds = null,
        private readonly ?string $auditDbPath = null,
        private readonly ?string $locale = null,
        /** @var list<string>|null */
        private readonly ?array $rulepacks = null,
        /** @var list<string>|null */
        private readonly ?array $rulepackPaths = null,
        private readonly bool $safetyNet = false,
        private readonly ?string $safetyNetDevice = null,
    ) {}

    public function clean(string $text): GazeSession
    {
        $this->assertInput($text);

        $command = [
            $this->resolver->resolve(),
            'clean',
            '--policy='.$this->resolvedPolicyPath(),
            '--format=json',
        ];

        if ($this->maxBytes !== null) {
            $command[] = '--max-bytes='.$this->maxBytes;
        }

        if ($this->sessionTtlSeconds !== null) {
            $command[] = '--session-ttl='.$this->sessionTtlSeconds;
        }

        if ($this->auditDbPath !== null && $this->auditDbPath !== '') {
            $command[] = '--audit-db='.$this->auditDbPath;
        }

        if ($this->locale !== null && $this->locale !== '') {
            $command[] = '--locale='.$this->locale;
        }

        foreach ($this->rulepacks ?? [] as $rulepack) {
            $command[] = '--rulepack-bundled='.$rulepack;
        }

        foreach ($this->rulepackPaths ?? [] as $path) {
            $command[] = '--rulepack-path='.$path;
        }

        if ($this->safetyNet) {
            $command[] = '--safety-net=openai-filter';
        }

        if ($this->safetyNetDevice !== null && $this->safetyNetDevice !== '') {
            $command[] = '--openai-filter-device='.$this->safetyNetDevice;
        }

        $result = $this->run($command, $text, 'clean');

        /** @var array{clean_text:string,session_blob:string,stats?:array{detections?:int}} $decoded */
        $decoded = $this->decodeResponse($result->output(), 'clean');

        return new GazeSession(
            cleanText: $decoded['clean_text'],
            ciphertext: EncryptedBlob::wrap($decoded['session_blob']),
            detections: (int) ($decoded['stats']['detections'] ?? 0),
        );
    }

    public function restore(GazeSession $session, string $text): string
    {
        $this->assertInput($text);

        try {
            $sessionBlob = $session->ciphertext->decryptedBlob();
        } catch (DecryptException $e) {
            $stderrHash = hash('sha256', '');
            $exception = new GazeResponseDecodeException(
                "gaze restore session blob could not be decrypted (exit=-1, stderr_sha256={$stderrHash})",
                exitCode: -1,
                stderrHash: $stderrHash,
                previous: $e,
            );
            Log::notice('gaze restore failed', $exception->toLogContext());

            throw $exception;
        }

        $payload = json_encode([
            'session_blob' => $sessionBlob,
            'text' => $text,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertInputSize($payload);

        $command = [$this->resolver->resolve(), 'restore', '--format=json'];
        if ($this->maxBytes !== null) {
            $command[] = '--max-bytes='.$this->maxBytes;
        }

        $result = $this->run($command, $payload, 'restore');

        /** @var array{text:string} $decoded */
        $decoded = $this->decodeResponse($result->output(), 'restore');

        return $decoded['text'];
    }

    public function audit(?string $auditDbPath = null): AuditService
    {
        if ($auditDbPath !== null && $auditDbPath !== '') {
            return new AuditService(
                gaze: $this,
                resolver: $this->resolver,
                auditDbPath: $auditDbPath,
            );
        }

        return $this->container->make(AuditService::class);
    }

    /**
     * @internal Audit-purge process invocation. Not a generic command runner;
     * hard-scoped to the `audit purge` stage.
     *
     * @param  list<string>  $command
     */
    public function runForAuditPurge(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit purge');
    }

    /**
     * @internal Audit-query process invocation. Not a generic command runner;
     * hard-scoped to the `audit query` stage.
     *
     * @param  list<string>  $command
     * @return ProcessResult
     */
    public function runForAuditQuery(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit query');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $output, string $stage): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $stderrHash = hash('sha256', '');
            $exception = new GazeResponseDecodeException(
                "gaze {$stage} response was not valid JSON (exit=-1, stderr_sha256={$stderrHash})",
                exitCode: -1,
                stderrHash: $stderrHash,
                previous: $e,
            );
            Log::notice("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        if (! is_array($decoded)) {
            $stderrHash = hash('sha256', '');
            $exception = new GazeResponseDecodeException(
                "gaze {$stage} response was not a JSON object (exit=-1, stderr_sha256={$stderrHash})",
                exitCode: -1,
                stderrHash: $stderrHash,
            );
            Log::notice("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $input, string $stage): ProcessResult
    {
        try {
            $result = $this->process
                ->newPendingProcess()
                ->timeout($this->timeoutSeconds)
                ->input($input)
                ->run($command);
        } catch (ProcessTimedOutException $e) {
            $stderrHash = hash('sha256', '');
            $exception = new GazeTimeoutException(
                "gaze {$stage} timed out (exit=-1, stderr_sha256={$stderrHash})",
                exitCode: -1,
                stderrHash: $stderrHash,
                previous: $e,
            );
            Log::warning("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        if ($result->successful()) {
            return $result;
        }

        throw $this->buildException($stage, $result);
    }

    private function assertInput(string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new GazeInvalidEncodingException('gaze input is not valid UTF-8', 1, hash('sha256', ''));
        }

        $this->assertInputSize($text);

        if ($text === '') {
            throw new GazeEmptyInputException('gaze input must not be empty', 1, hash('sha256', ''));
        }
    }

    private function assertInputSize(string $input): void
    {
        if (strlen($input) > ($this->maxBytes ?? self::DEFAULT_MAX_BYTES)) {
            throw new GazeInputTooLargeException('gaze input exceeds max_bytes pre-flight', 1, hash('sha256', ''));
        }
    }

    private function resolvedPolicyPath(): string
    {
        $policyPath = $this->policyPath ?? base_path('policy.toml');

        if ($policyPath === '') {
            throw new \RuntimeException('gaze.policy_path must not be empty.');
        }

        return $policyPath;
    }

    private function buildException(string $stage, ProcessResult $result): GazeException
    {
        $stderr = $result->errorOutput() ?: '';
        $exitCode = $result->exitCode() ?? -1;
        $stderrHash = hash('sha256', $stderr);

        if ($exitCode === 141 && $stderr === '') {
            $exception = new GazeSigPipeException(
                "gaze {$stage} terminated by SIGPIPE (exit=141, stderr_sha256={$stderrHash})",
                141,
                $stderrHash,
            );
            Log::debug("gaze {$stage} failed", $exception->toLogContext());

            return $exception;
        }

        // Non-empty stderr on exit 141 still goes through the normal stderr
        // safelist parser so upstream can surface a typed variant if it emits one.
        $variant = Variant::tryFromStderr($stderr, $exitCode);
        $exception = match ($variant) {
            Variant::StdinParse => new GazeStdinParseException(
                "gaze {$stage} stdin parse failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::EmptyInput => new GazeEmptyInputException(
                "gaze {$stage} input was empty (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::InputTooLarge => new GazeInputTooLargeException(
                "gaze {$stage} input exceeded max bytes (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::InvalidEncoding => new GazeInvalidEncodingException(
                "gaze {$stage} input encoding invalid (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::PolicyConfig => new GazePolicyConfigException(
                "gaze {$stage} policy configuration invalid (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::PolicyConfigDetail => new GazePolicyConfigDetailException(
                "gaze {$stage} policy configuration invalid (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::AuditPurgeIso8601 => new GazeAuditPurgeIso8601Exception(
                "gaze {$stage} audit purge timestamp not ISO8601 (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::UnknownToken => new GazeUnknownTokenException(
                "gaze {$stage} encountered unknown token (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::InvalidSignature => new GazeInvalidSignatureException(
                "gaze {$stage} session signature invalid (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::InvalidBlobVersion => new GazeInvalidBlobVersionException(
                "gaze {$stage} blob version invalid (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::BlobExpired => new GazeBlobExpiredException(
                "gaze {$stage} blob expired (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::Pipeline => new GazePipelineException(
                "gaze {$stage} pipeline failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::Io => new GazeIoException(
                "gaze {$stage} I/O failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::SigPipe => new GazeSigPipeException(
                "gaze {$stage} terminated by SIGPIPE (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
            Variant::PolicyOpen => new GazePolicyOpenException(
                "gaze {$stage} policy open failed (exit={$exitCode}, stderr_sha256={$stderrHash})",
                $exitCode,
                $stderrHash,
            ),
        };

        Log::{$exception->logLevel()}("gaze {$stage} failed", $exception->toLogContext());

        return $exception;
    }
}
