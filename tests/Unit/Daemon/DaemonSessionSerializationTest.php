<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\DaemonClient;
use Naoray\GazeLaravel\Daemon\DaemonSession;

it('throws when an adopter tries to serialize a DaemonSession', function () {
    $stdin = fopen('php://temp', 'w+');
    $stdout = fopen('php://temp', 'w+');
    $client = DaemonClient::withStreams($stdin, $stdout);
    $session = new DaemonSession('queue-leak', $client);

    expect(fn () => serialize($session))->toThrow(\LogicException::class);
});

it('throws when an adopter tries to unserialize a DaemonSession payload', function () {
    // Build a synthetic serialize() payload (manual, since we cannot use serialize() here).
    $payload = 'O:39:"Naoray\\GazeLaravel\\Daemon\\DaemonSession":0:{}';

    expect(fn () => unserialize($payload))->toThrow(\LogicException::class);
});
