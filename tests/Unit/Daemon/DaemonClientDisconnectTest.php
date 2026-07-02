<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;

/**
 * disconnect() must never hang: proc_close() blocks until the child is
 * gone, so a daemon that ignores SIGTERM has to be escalated to SIGKILL
 * after a short grace period. A cooperative daemon must exit well before
 * the grace period so teardown stays fast on the happy path.
 */
it('disconnects promptly when the daemon honours SIGTERM', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-daemon-term-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    // Default signal disposition: SIGTERM terminates the sleep.
    $binary = gl_makeProcessFixture($tmpDir, 'cooperative-daemon', 'sleep(30);');

    $client = new DaemonClient(binary: $binary, flags: []);
    $client->connect();

    $start = microtime(true);
    $client->disconnect();
    $elapsed = microtime(true) - $start;

    // Must return long before the 2s SIGKILL grace period, i.e. no
    // needless full-grace wait for a child that already exited.
    expect($elapsed)->toBeLessThan(1.5);

    gl_recursiveRemove($tmpDir);
});

it('escalates to SIGKILL when the daemon ignores SIGTERM', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-daemon-kill-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    // A daemon that traps and ignores SIGTERM. Without SIGKILL escalation,
    // proc_close() inside disconnect() would block until the 30 iterations
    // elapse (and forever for a truly wedged child). The ready-file marks
    // the trap as installed so the SIGTERM below cannot race child startup.
    $ready = $tmpDir.'/ready';
    $binary = $tmpDir.'/stubborn-daemon';
    file_put_contents(
        $binary,
        "#!/bin/sh\ntrap '' TERM\n: > ".escapeshellarg($ready)."\ni=0\nwhile [ \$i -lt 30 ]; do sleep 1; i=\$((i+1)); done\n",
    );
    chmod($binary, 0755);

    $client = new DaemonClient(binary: $binary, flags: []);
    $client->connect();

    $spawnDeadline = microtime(true) + 5.0;
    while (! file_exists($ready)) {
        if (microtime(true) > $spawnDeadline) {
            throw new RuntimeException('stubborn-daemon fixture never became ready');
        }
        usleep(10_000);
    }

    $start = microtime(true);
    $client->disconnect();
    $elapsed = microtime(true) - $start;

    // SIGTERM is ignored, so the ~2s grace period elapses, then SIGKILL
    // reaps the child — well under the 30s the fixture would otherwise run.
    expect($elapsed)->toBeGreaterThan(1.5);
    expect($elapsed)->toBeLessThan(6.0);

    gl_recursiveRemove($tmpDir);
});

it('disconnect stays idempotent after escalation teardown', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-daemon-idem-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    $binary = gl_makeProcessFixture($tmpDir, 'cooperative-daemon', 'sleep(30);');

    $client = new DaemonClient(binary: $binary, flags: []);
    $client->connect();

    $client->disconnect();
    $client->disconnect(); // second call must be a no-op, not an error

    // Fail-closed posture is preserved: a disconnected client refuses work.
    expect(fn () => $client->request('after-close', 'hi'))
        ->toThrow(GazeDaemonTransportException::class);

    gl_recursiveRemove($tmpDir);
});
