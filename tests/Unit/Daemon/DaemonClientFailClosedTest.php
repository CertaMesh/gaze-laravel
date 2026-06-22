<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;

it('throws GazeDaemonTransportException on EOF from daemon stdout', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream();
    // empty stdout = immediate EOF

    $client = DaemonClient::withStreams($stdin, $stdout);

    expect(fn () => $client->request('eof-session', 'hi'))
        ->toThrow(GazeDaemonTransportException::class);
});

it('does not auto-reconnect after EOF — second request still throws', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream();

    $client = DaemonClient::withStreams($stdin, $stdout);

    try {
        $client->request('s1', 'hi');
    } catch (GazeDaemonTransportException) {
    }

    expect(fn () => $client->request('s2', 'second'))
        ->toThrow(GazeDaemonException::class);
});

it('throws when daemon echoes a different session_id (no silent payload swap)', function () {
    $stdin = gl_memoryStream();
    $stdout = gl_memoryStream(gl_jsonEncode([
        'session_id' => 'OTHER',
        'clean_text' => 'wrong-tenant',
        'manifest' => [],
        'tokens' => [],
    ])."\n");

    $client = DaemonClient::withStreams($stdin, $stdout);

    try {
        $client->request('expected', 'hi');
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonException $e) {
        expect($e->daemonVariant())->toBe(DaemonErrorVariant::Transport);
        expect($e->getMessage())->toContain('mismatched session_id');
    }
});
