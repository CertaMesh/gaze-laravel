<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeBlobExpiredException;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeInvalidBlobVersionException;
use CertaMesh\Gaze\Exceptions\GazeInvalidEncodingException;
use CertaMesh\Gaze\Exceptions\GazeInvalidSignatureException;
use CertaMesh\Gaze\Exceptions\GazeIoException;
use CertaMesh\Gaze\Exceptions\GazePipelineException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigException;
use CertaMesh\Gaze\Exceptions\GazePolicyOpenException;
use CertaMesh\Gaze\Exceptions\GazePolicySchemaUnsupportedException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetArtifactMissingException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Exceptions\GazeSigPipeException;
use CertaMesh\Gaze\Exceptions\GazeUnknownTokenException;
use CertaMesh\Gaze\Exceptions\GazeUnsupportedSessionScopeException;
use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Queue\Contracts\RequiresFreshClean;
use Illuminate\Support\Facades\Process;

/** @param class-string<GazeException> $class */
it('maps variants to their dedicated exception classes', function (array $payload, string $class) {
    if (! is_a($class, GazeException::class, true)) {
        throw new RuntimeException('expected GazeException subclass');
    }

    $error = $payload['error'];
    $payload['exit'] = match ($error) {
        'PolicyConfig', 'PolicySchemaUnsupported', 'SafetyNetArtifactMissing' => 2,
        'Io', 'PolicyOpen' => 4,
        'SigPipe' => 141,
        default => 3,
    };
    $stderr = json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL;

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: $stderr,
            exitCode: match ($error) {
                'PolicyConfig', 'PolicySchemaUnsupported', 'SafetyNetArtifactMissing' => 2,
                'Io', 'PolicyOpen' => 4,
                'SigPipe' => 141,
                default => 3,
            },
        ),
    ]);

    try {
        $this->makeGaze()->restore(
            $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1),
            'Hello Name_1',
        );
    } catch (GazeException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->stderrHash)->toBe(hash('sha256', $stderr));

        return;
    }

    $this->fail("Expected {$class} to be thrown.");
})->with([
    [['error' => 'UnknownToken'], GazeUnknownTokenException::class],
    [['error' => 'InvalidSignature'], GazeInvalidSignatureException::class],
    [['error' => 'InvalidBlobVersion'], GazeInvalidBlobVersionException::class],
    [['error' => 'BlobExpired'], GazeBlobExpiredException::class],
    [['error' => 'Pipeline'], GazePipelineException::class],
    [['error' => 'PolicyConfig'], GazePolicyConfigException::class],
    [['error' => 'PolicyConfig', 'detail' => 'unknown bundled rulepack: garbage'], GazePolicyConfigDetailException::class],
    [['error' => 'PolicySchemaUnsupported', 'found' => '9.9.0', 'supported' => '0.1'], GazePolicySchemaUnsupportedException::class],
    [['error' => 'SafetyNetConfig', 'detail' => 'missing config'], GazeSafetyNetConfigException::class],
    [['error' => 'SafetyNet', 'variant' => 'Timeout'], GazeSafetyNetFailureException::class],
    [['error' => 'SafetyNetArtifactMissing', 'backend' => 'kiji-distilbert', 'path' => '/var/lib/gaze/models/kiji'], GazeSafetyNetArtifactMissingException::class],
    [['error' => 'UnsupportedSessionScope', 'variant' => 'global'], GazeUnsupportedSessionScopeException::class],
    [['error' => 'Io'], GazeIoException::class],
    [['error' => 'SigPipe'], GazeSigPipeException::class],
    [['error' => 'PolicyOpen'], GazePolicyOpenException::class],
]);

it('exposes safety-net and session-scope sidecar fields', function () {
    $safetyNet = new GazeSafetyNetFailureException('safety', 3, hash('sha256', ''), 'SuspectedLeak');
    $scope = new GazeUnsupportedSessionScopeException('scope', 3, hash('sha256', ''), 'global');

    expect($safetyNet->safetyNetVariant())->toBe('SuspectedLeak')
        ->and($scope->attemptedScope())->toBe('global');
});

it('exposes the upstream PolicyConfig detail sidecar through the typed exception', function () {
    $payload = ['error' => 'PolicyConfig', 'exit' => 2, 'detail' => 'unknown bundled rulepack: garbage'];
    $stderr = json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL;

    Process::fake(['*' => Process::result(output: '', errorOutput: $stderr, exitCode: 2)]);

    try {
        $this->makeGaze()->restore($this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1), 'Hello Name_1');
    } catch (GazePolicyConfigDetailException $e) {
        expect($e->detail())->toBe('unknown bundled rulepack: garbage');

        return;
    }

    $this->fail('Expected GazePolicyConfigDetailException to be thrown.');
});

it('exposes the upstream SafetyNetArtifactMissing backend/path sidecars', function () {
    $payload = [
        'error' => 'SafetyNetArtifactMissing',
        'exit' => 2,
        'backend' => 'kiji-distilbert',
        'path' => '/var/lib/gaze/models/kiji',
    ];
    $stderr = json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL;

    Process::fake(['*' => Process::result(output: '', errorOutput: $stderr, exitCode: 2)]);

    try {
        $this->makeGaze()->restore($this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1), 'Hello Name_1');
    } catch (GazeSafetyNetArtifactMissingException $e) {
        expect($e->backend())->toBe('kiji-distilbert')
            ->and($e->path())->toBe('/var/lib/gaze/models/kiji')
            ->and($e)->toBeInstanceOf(NonRetryable::class)
            ->and($e->variant?->value)->toBe('SafetyNetArtifactMissing')
            ->and($e->variant?->exitBucket())->toBe(2);

        return;
    }

    $this->fail('Expected GazeSafetyNetArtifactMissingException to be thrown.');
});

it('exposes the upstream PolicySchemaUnsupported found/supported sidecars', function () {
    $payload = ['error' => 'PolicySchemaUnsupported', 'exit' => 2, 'found' => '9.9.0', 'supported' => '0.1'];
    $stderr = json_encode($payload, JSON_THROW_ON_ERROR).PHP_EOL;

    Process::fake(['*' => Process::result(output: '', errorOutput: $stderr, exitCode: 2)]);

    try {
        $this->makeGaze()->restore($this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1), 'Hello Name_1');
    } catch (GazePolicySchemaUnsupportedException $e) {
        expect($e->found())->toBe('9.9.0')
            ->and($e->supported())->toBe('0.1');

        return;
    }

    $this->fail('Expected GazePolicySchemaUnsupportedException to be thrown.');
});

it('marks blob-expired variants as requiring a fresh clean', function () {
    $exception = new GazeBlobExpiredException('expired', 3, hash('sha256', ''));

    expect($exception)->toBeInstanceOf(RequiresFreshClean::class)
        ->and($exception->requiresFreshClean())->toBeTrue();
});

it('marks invalid-blob-version variants as requiring a fresh clean', function () {
    $exception = new GazeInvalidBlobVersionException('expired', 3, hash('sha256', ''));

    expect($exception)->toBeInstanceOf(RequiresFreshClean::class)
        ->and($exception->requiresFreshClean())->toBeTrue();
});

it('keeps exception log levels next to exception classes', function () {
    $io = new GazeIoException('io', 4, hash('sha256', ''));
    $unknown = new GazeUnknownTokenException('unknown', 3, hash('sha256', ''));
    $policy = new GazePolicyConfigException('policy', 2, hash('sha256', ''));

    expect($io->logLevel())->toBe('warning')
        ->and($unknown->logLevel())->toBe('notice')
        ->and($policy->logLevel())->toBe('notice');
});

it('throws invalid encoding before starting a subprocess', function () {
    Process::fake();

    $this->makeGaze()->clean("\xFF");
})->throws(GazeInvalidEncodingException::class);
