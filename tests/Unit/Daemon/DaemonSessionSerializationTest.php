<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonSession;

it('throws when an adopter tries to serialize a DaemonSession', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream();
    $client = DaemonClient::withStreams($stdin, $stdout);
    $session = new DaemonSession('queue-leak', $client);

    expect(fn () => serialize($session))->toThrow(LogicException::class);
});

it('throws when an adopter tries to unserialize a DaemonSession payload', function () {
    // Build a synthetic serialize() payload (manual, since we cannot use serialize() here).
    $payload = 'O:35:"CertaMesh\\Gaze\\Daemon\\DaemonSession":0:{}';

    expect(fn () => unserialize($payload))->toThrow(LogicException::class);
});
