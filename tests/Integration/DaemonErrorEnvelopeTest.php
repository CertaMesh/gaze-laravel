<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->binary = $binary;
});

it('surfaces a blank session id as ProtocolInvalid from the real daemon', function () {
    $client = new DaemonClient($this->binary, ['--policy='.gl_integrationPolicyPath()]);

    try {
        foreach (['', '   '] as $sessionId) {
            try {
                $client->request($sessionId, 'hi');
                throw new RuntimeException('did not throw');
            } catch (GazeDaemonException $e) {
                expect($e->daemonVariant())->toBe(DaemonErrorVariant::ProtocolInvalid)
                    ->and($e->sessionId())->toBeNull()
                    ->and($e->raw())->toBe(['session_id' => null, 'error' => 'ProtocolInvalid', 'detail' => 'missing session_id']);
            }
        }

        // The daemon keeps serving after a protocol error.
        expect($client->request('s1', 'hi')->sessionId)->toBe('s1');
    } finally {
        $client->disconnect();
    }
});
