<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonEnvelopeParser;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Daemon\SessionIdDigest;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeDaemonFeatureUnsupportedException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTimeoutException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;

/*
 * #181: adopters choose daemon session ids and may build them from user data.
 * Exception messages and toLogContext() carry only a 12-hex SHA-256 digest
 * (`session_id_sha256`); sessionId() / raw() keep the raw values for code.
 */

const SIR_SENT = 'tenant-7:alice@example.com';
const SIR_GOT = 'tenant-9:bob@example.org';

function sir_digest(string $id): string
{
    return substr(hash('sha256', $id), 0, 12);
}

/**
 * Everything a log line would carry: the message plus the encoded context.
 */
function sir_logged(GazeDaemonException $e): string
{
    return $e->getMessage()."\n".gl_jsonEncode($e->toLogContext(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

it('digests a session id as the first 12 hex chars of its sha256', function () {
    expect(SessionIdDigest::of(SIR_SENT))->toBe(sir_digest(SIR_SENT))
        ->toMatch('/^[0-9a-f]{12}$/');
});

it('hashes both ids in the mismatched-session_id message and log context', function () {
    $stdout = gl_memoryStream(gl_jsonEncode([
        'session_id' => SIR_GOT,
        'clean_text' => 'reply to carol@example.net about <56064804:Email_1>',
        'manifest' => [],
        'tokens' => [],
    ])."\n");

    $client = DaemonClient::withStreams(gl_memoryStream(), $stdout);

    try {
        $client->request(SIR_SENT, 'hi');
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonTransportException $e) {
        $logged = sir_logged($e);

        expect($logged)->not->toContain('alice@example.com')
            ->not->toContain('bob@example.org')
            ->not->toContain('tenant-')
            // The other request's clean text is payload, not diagnostics.
            ->not->toContain('carol@example.net');

        expect($e->getMessage())->toBe(
            'daemon echoed mismatched session_id (session_id_sha256 sent='.sir_digest(SIR_SENT).', got='.sir_digest(SIR_GOT).')'
        );

        $context = $e->toLogContext();
        expect($context)->not->toHaveKey('session_id')
            ->and($context['session_id_sha256'])->toBe(sir_digest(SIR_SENT))
            ->and($context['raw'])->toBe([
                'session_id_sha256' => sir_digest(SIR_GOT),
                'clean_text_sha256' => hash('sha256', 'reply to carol@example.net about <56064804:Email_1>'),
                'manifest' => [],
                'tokens' => [],
            ]);

        // Code keeps the raw values.
        expect($e->sessionId())->toBe(SIR_SENT)
            ->and($e->raw()['session_id'])->toBe(SIR_GOT);
    }
});

it('hashes the session id in the log context of transport faults', function () {
    $client = DaemonClient::withStreams(gl_memoryStream(), gl_memoryStream());

    try {
        $client->request(SIR_SENT, 'hi');
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonTransportException $e) {
        expect(sir_logged($e))->not->toContain('alice@example.com')
            ->and($e->toLogContext()['session_id_sha256'])->toBe(sir_digest(SIR_SENT))
            ->and($e->sessionId())->toBe(SIR_SENT);
    }
});

it('hashes the session id in the log context of request timeouts', function () {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('stream_socket_pair failed');
    }
    [$ourEnd, $emptyEnd] = $pair;

    $client = DaemonClient::withStreams(gl_memoryStream(), $ourEnd, requestTimeoutMs: 50);

    try {
        $client->request(SIR_SENT, 'hi');
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonTimeoutException $e) {
        expect(sir_logged($e))->not->toContain('alice@example.com')
            ->and($e->toLogContext()['session_id_sha256'])->toBe(sir_digest(SIR_SENT))
            ->and($e->sessionId())->toBe(SIR_SENT);
    } finally {
        @fclose($emptyEnd);
    }
});

it('hashes the session id an upstream error envelope echoes', function () {
    $envelope = ['session_id' => SIR_SENT, 'error' => 'Pipeline', 'detail' => 'gaze daemon request failed closed'];

    $e = DaemonEnvelopeParser::parse(gl_jsonEncode($envelope), SIR_SENT);

    expect($e)->toBeInstanceOf(GazeDaemonException::class);
    assert($e instanceof GazeDaemonException);

    expect(sir_logged($e))->not->toContain('alice@example.com')
        ->and($e->toLogContext())->toBe([
            'daemon_variant' => 'Pipeline',
            'session_id_sha256' => sir_digest(SIR_SENT),
            'raw' => [
                'session_id_sha256' => sir_digest(SIR_SENT),
                'error' => 'Pipeline',
                'detail' => 'gaze daemon request failed closed',
            ],
        ])
        ->and($e->sessionId())->toBe(SIR_SENT)
        ->and($e->raw())->toBe($envelope);
});

it('hashes a malformed line, which can carry the session id and clean text', function () {
    // A truncated success envelope: not JSON, but full of adopter data.
    $line = '{"session_id":"'.SIR_SENT.'","clean_text":"reply to carol@example.net';

    $e = DaemonEnvelopeParser::parse($line, SIR_SENT);

    expect($e)->toBeInstanceOf(GazeDaemonException::class);
    assert($e instanceof GazeDaemonException);

    expect(sir_logged($e))->not->toContain('alice@example.com')
        ->not->toContain('carol@example.net')
        ->and($e->daemonVariant())->toBe(DaemonErrorVariant::JsonMalformed)
        ->and($e->toLogContext()['raw'])->toBe(['raw_line_sha256' => hash('sha256', $line)])
        ->and($e->raw())->toBe(['raw_line' => $line]);
});

it('hashes the session id for every daemon exception class', function (GazeDaemonException $e) {
    expect(sir_logged($e))->not->toContain('alice@example.com')
        ->and($e->toLogContext())->not->toHaveKey('session_id')
        ->and($e->toLogContext()['session_id_sha256'])->toBe(sir_digest(SIR_SENT))
        ->and($e->sessionId())->toBe(SIR_SENT);
})->with([
    'base' => fn () => new GazeDaemonException('x', SIR_SENT, [], DaemonErrorVariant::Pipeline),
    'transport' => fn () => new GazeDaemonTransportException('concurrent daemon request rejected', SIR_SENT),
    'timeout' => fn () => new GazeDaemonTimeoutException('daemon request exceeded 50ms', SIR_SENT),
    'feature unsupported' => fn () => new GazeDaemonFeatureUnsupportedException(sessionId: SIR_SENT),
]);

it('keeps a null session id null in the log context', function () {
    $e = new GazeDaemonException('daemon response was not valid JSON', null, ['session_id' => null], DaemonErrorVariant::JsonMalformed);

    expect($e->toLogContext()['session_id_sha256'])->toBeNull()
        ->and($e->toLogContext()['raw'])->toBe(['session_id_sha256' => null]);
});
