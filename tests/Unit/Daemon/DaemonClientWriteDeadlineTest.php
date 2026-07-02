<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonTimeoutException;

/**
 * A wedged daemon that stops draining its stdin pipe must not hang the PHP
 * worker past `request_timeout_ms`. The write path is non-blocking and
 * stream_select()-bounded, failing closed with the same timeout exception
 * the read path uses.
 */
it('throws GazeDaemonTimeoutException when daemon stdin stays full past the deadline', function () {
    // A socket pair whose far end is never read: the kernel buffer fills,
    // then the write side stays non-writable — exactly a wedged daemon.
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) {
        throw new RuntimeException('stream_socket_pair failed');
    }
    [$ourStdin, $neverDrained] = $pair;

    $client = DaemonClient::withStreams($ourStdin, gl_memoryStream(), requestTimeoutMs: 50);

    // Far larger than any kernel socket buffer, so the write cannot finish.
    $payload = 'SECRET-PII-MARKER '.str_repeat('z', 4 * 1024 * 1024);

    $start = microtime(true);
    try {
        $client->request('write-timeout', $payload);
        throw new RuntimeException('did not throw');
    } catch (GazeDaemonTimeoutException $e) {
        expect($e->daemonVariant())->toBe(DaemonErrorVariant::Timeout);
        expect($e->sessionId())->toBe('write-timeout');
        // PII discipline: the exception must never carry payload text.
        expect($e->getMessage())->not->toContain('SECRET-PII-MARKER');
    }
    $elapsed = microtime(true) - $start;

    // Deadline actually bounded the hang (50ms budget, generous CI slack).
    expect($elapsed)->toBeLessThan(2.0);

    @fclose($neverDrained);
    @fclose($ourStdin);
});

it('completes a frame larger than the pipe buffer once a slow daemon drains stdin', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-daemon-write-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    // Fake daemon: sleeps before reading so the client's first fwrite()
    // can only partially fill the ~64KB pipe buffer, then drains stdin and
    // echoes the received text length. Exercises the select-write loop's
    // multi-round progress path (not just the timeout path).
    $binary = gl_makeProcessFixture($tmpDir, 'slow-reader-daemon', <<<'PHP'
    usleep(150_000);
    $line = fgets(STDIN);
    $request = json_decode((string) $line, true);
    fwrite(STDOUT, json_encode([
        'session_id' => $request['session_id'],
        'clean_text' => (string) strlen($request['text']),
        'manifest' => [],
        'tokens' => [],
    ])."\n");
    PHP);

    $client = new DaemonClient(binary: $binary, flags: [], requestTimeoutMs: 5000);

    $text = str_repeat('a', 512 * 1024); // far beyond the pipe buffer

    try {
        $response = $client->request('big-write', $text);

        expect($response->sessionId)->toBe('big-write');
        expect($response->cleanText)->toBe((string) strlen($text));
    } finally {
        $client->disconnect();
        gl_recursiveRemove($tmpDir);
    }
});
