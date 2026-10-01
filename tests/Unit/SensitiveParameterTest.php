<?php

declare(strict_types=1);

use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonEnvelopeParser;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Daemon\DaemonSession;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Gaze;
use Illuminate\Support\Facades\Process;

/**
 * With `zend.exception_ignore_args=Off` (PHP's development default) PHP keeps
 * call arguments in stack traces, and error trackers log them. Every parameter
 * that carries raw input, a response line holding `entries[].raw`, a plaintext
 * session blob or an adopter session id must be `#[\SensitiveParameter]` so it
 * is recorded as `SensitiveParameterValue` instead (#195).
 */
const SENSITIVE_PARAMETERS = [
    [Gaze::class, 'clean', 'text'],
    [Gaze::class, 'mask', 'text'],
    [Gaze::class, 'restore', 'text'],
    [Gaze::class, 'run', 'input'],
    [Gaze::class, 'decodeResponse', 'output'],
    [Gaze::class, 'mapEntries', 'raw'],
    [Gaze::class, 'assertInput', 'text'],
    [Gaze::class, 'assertInputSize', 'input'],
    [GazeContract::class, 'clean', 'text'],
    [GazeContract::class, 'mask', 'text'],
    [GazeContract::class, 'restore', 'text'],
    [DaemonClient::class, 'request', 'sessionId'],
    [DaemonClient::class, 'request', 'text'],
    [DaemonClient::class, 'writeRequest', 'payload'],
    [DaemonClient::class, 'writeRequest', 'sessionId'],
    [DaemonClient::class, 'readLine', 'sessionId'],
    [DaemonManager::class, 'clean', 'sessionId'],
    [DaemonManager::class, 'clean', 'text'],
    [DaemonSession::class, 'clean', 'text'],
    [DaemonEnvelopeParser::class, 'parse', 'line'],
    [DaemonEnvelopeParser::class, 'parse', 'expectedSessionId'],
    [CleanResponse::class, 'fromArray', 'decoded'],
    [Entry::class, 'fromArray', 'payload'],
    [EncryptedBlob::class, 'wrap', 'plaintextBlob'],
];

it('marks every raw-data parameter #[\SensitiveParameter]', function (string $class, string $method, string $param) {
    $attributes = null;
    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        if ($parameter->getName() === $param) {
            $attributes = $parameter->getAttributes(SensitiveParameter::class);
        }
    }

    expect($attributes)->not->toBeNull()->toHaveCount(1);
})->with(SENSITIVE_PARAMETERS);

/**
 * Every string an error tracker could read from the recorded call arguments
 * (strings, recursively inside arrays; objects are skipped — trackers print
 * them by class, and test-framework objects are circular).
 */
function gl_traceArgStrings(Throwable $e): string
{
    $out = '';
    $walk = function (mixed $value, int $depth) use (&$walk, &$out): void {
        if (is_string($value)) {
            $out .= $value."\n";
        } elseif (is_array($value) && $depth < 4) {
            foreach ($value as $item) {
                $walk($item, $depth + 1);
            }
        }
    };

    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
        foreach ($t->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $arg) {
                $walk($arg, 0);
            }
        }
    }

    return $out;
}

it('keeps raw input out of a failing clean()\'s stack trace', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        Process::fake(['*' => Process::result(output: '', errorOutput: '{"error":"Pipeline","exit":3}', exitCode: 3)]);

        try {
            $this->makeGaze()->clean('SECRET-PII jane.doe@example.com');
        } catch (GazeException $e) {
            expect($e->getTraceAsString())->not->toContain('SECRET-PII')
                ->and(gl_traceArgStrings($e))->not->toContain('SECRET-PII');

            return;
        }

        $this->fail('Expected a GazeException.');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('keeps the session id and text out of a daemon failure\'s stack trace', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $stdout = gl_memoryStream(gl_jsonEncode(['session_id' => 'OTHER', 'clean_text' => 'x', 'manifest' => [], 'tokens' => []])."\n");
        $client = DaemonClient::withStreams(gl_memoryStream(), $stdout);

        try {
            $client->request('tenant-anna.schmidt', 'SECRET-PII text');
        } catch (GazeDaemonException $e) {
            $trace = $e->getTraceAsString().gl_traceArgStrings($e);

            expect($trace)->not->toContain('SECRET-PII')
                ->and($trace)->not->toContain('anna.schmidt');

            return;
        }

        $this->fail('Expected a GazeDaemonException.');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});
