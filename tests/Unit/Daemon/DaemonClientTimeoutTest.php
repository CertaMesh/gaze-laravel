<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Daemon\DaemonClient;
use Naoray\GazeLaravel\Daemon\DaemonErrorVariant;
use Naoray\GazeLaravel\Exceptions\GazeDaemonTimeoutException;

it('throws GazeDaemonTimeoutException when stdout produces no line in time', function () {
    $stdin = gl_memoryStream();

    // A socket pair with no writer keeps stdout open but never delivers data
    // — stream_select() will block until our deadline expires.
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('stream_socket_pair failed');
    }
    [$ourEnd, $emptyEnd] = $pair;

    $client = DaemonClient::withStreams($stdin, $ourEnd, requestTimeoutMs: 50);

    try {
        $client->request('timeout-session', 'hi');
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonTimeoutException $e) {
        expect($e->daemonVariant())->toBe(DaemonErrorVariant::Timeout);
        expect($e->sessionId())->toBe('timeout-session');
    }

    @fclose($emptyEnd);
});
