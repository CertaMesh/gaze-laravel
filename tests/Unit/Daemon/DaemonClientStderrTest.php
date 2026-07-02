<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;

/**
 * Child stderr must never be wired to a pipe. Nothing in the request loop
 * reads stderr, so a pipe would let a chatty daemon fill the ~64KB kernel
 * buffer, block on its next stderr write, and time out every subsequent
 * request. The spec routes stderr to a file descriptor that cannot exert
 * backpressure instead.
 */
it('routes child stderr to /dev/null when no stderrPath is configured', function () {
    $spec = DaemonClient::descriptorSpec(null);

    expect($spec[0])->toBe(['pipe', 'r']);
    expect($spec[1])->toBe(['pipe', 'w']);
    expect($spec[2])->toBe(['file', '/dev/null', 'a']);
});

it('routes child stderr to the configured stderrPath in append mode', function () {
    $spec = DaemonClient::descriptorSpec('/var/log/gaze-daemon.log');

    expect($spec[2])->toBe(['file', '/var/log/gaze-daemon.log', 'a']);
});

it('completes a request against a daemon that floods stderr past the pipe buffer', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-daemon-stderr-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    // Fake daemon: spams ~320KB to stderr *before* touching stdin. With a
    // stderr pipe nobody drains, the child blocks at ~64KB and never
    // responds — this request would time out.
    $binary = gl_makeProcessFixture($tmpDir, 'flood-daemon', <<<'PHP'
    $noise = str_repeat('n', 8191)."\n";
    for ($i = 0; $i < 40; $i++) {
        fwrite(STDERR, $noise);
    }
    $line = fgets(STDIN);
    $request = json_decode((string) $line, true);
    fwrite(STDOUT, json_encode([
        'session_id' => $request['session_id'],
        'clean_text' => 'ok',
        'manifest' => [],
        'tokens' => [],
    ])."\n");
    PHP);

    $client = new DaemonClient(binary: $binary, flags: [], requestTimeoutMs: 5000);

    try {
        $response = $client->request('flood-session', 'hello');

        expect($response->sessionId)->toBe('flood-session');
        expect($response->cleanText)->toBe('ok');
    } finally {
        $client->disconnect();
        gl_recursiveRemove($tmpDir);
    }
});
