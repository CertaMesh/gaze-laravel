<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\CleanResponse;
use Naoray\GazeLaravel\Daemon\DaemonEnvelopeParser;
use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;
use Naoray\GazeLaravel\Exceptions\GazeDaemonException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTimeoutException;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTransportException;

it('returns CleanResponse for success envelopes', function () {
    $line = gl_jsonEncode([
        'session_id' => 's1',
        'clean_text' => 'masked',
        'manifest' => [],
        'tokens' => [],
    ]);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(CleanResponse::class);
    expect($result instanceof CleanResponse)->toBeTrue();
    if ($result instanceof CleanResponse) {
        expect($result->sessionId)->toBe('s1');
    }
});

it('returns GazeDaemonException for JsonMalformed wire variant', function () {
    $line = gl_jsonEncode(['session_id' => null, 'error' => 'JsonMalformed', 'detail' => 'malformed JSON line']);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::JsonMalformed);
        expect($result->getMessage())->toContain('malformed JSON line');
    }
});

it('returns GazeDaemonException for Pipeline wire variant', function () {
    $line = gl_jsonEncode(['session_id' => 's1', 'error' => 'Pipeline', 'detail' => 'failed closed']);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::Pipeline);
        expect($result->sessionId())->toBe('s1');
    }
});

it('returns GazeDaemonTransportException for Transport wire variant', function () {
    $line = gl_jsonEncode(['session_id' => 's1', 'error' => 'Transport', 'detail' => 'broken pipe']);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(GazeDaemonTransportException::class);
    if ($result instanceof GazeDaemonTransportException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::Transport);
    }
});

it('returns GazeDaemonTimeoutException for Timeout wire variant', function () {
    $line = gl_jsonEncode(['session_id' => 's1', 'error' => 'Timeout', 'detail' => 'deadline exceeded']);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(GazeDaemonTimeoutException::class);
});

it('routes unknown wire variants to Unknown sink carrying raw payload', function () {
    $line = gl_jsonEncode([
        'session_id' => 's1',
        'error' => 'SomeFutureVariant',
        'detail' => 'forward-compat',
        'extra' => 'forward-compat-field',
    ]);

    $result = DaemonEnvelopeParser::parse($line);

    expect($result)->toBeInstanceOf(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::Unknown);
        expect($result->raw())->toHaveKey('extra', 'forward-compat-field');
    }
});

it('returns GazeDaemonException with JsonMalformed when the line is not valid JSON', function () {
    $result = DaemonEnvelopeParser::parse('not-json{');

    expect($result)->toBeInstanceOf(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::JsonMalformed);
    }
});

it('returns GazeDaemonException with JsonMalformed when the JSON is not an object', function () {
    $result = DaemonEnvelopeParser::parse('"plain string"');

    expect($result)->toBeInstanceOf(GazeDaemonException::class);
    if ($result instanceof GazeDaemonException) {
        expect($result->daemonVariant())->toBe(DaemonErrorVariant::JsonMalformed);
    }
});
