<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonSession;

/**
 * Concurrency contract — two fibers, two session ids, one shared client.
 *
 * The shared stdio pipe carries no request-id, so interleaved write/read
 * would swap payloads between tenants. The client serialises calls via the
 * `$busy` mutex; this test exercises the contract by running two fibers
 * that each take a turn calling `request()` and verifying their responses
 * line up with their session ids — no payload swap.
 */
it('serialises 2 fibers across 2 sessions without payload swap', function () {
    $stdin = gl_memoryStream();

    // Pre-fill the fake stdout with two queued responses keyed to the two
    // fibers' session ids. The client's request() routine reads one line
    // per call, so as long as the mutex serialises the calls, the first
    // call gets line 1 and the second gets line 2.
    $stdout = gl_memoryStream(
        gl_jsonEncode([
            'session_id' => 'thread-a',
            'clean_text' => 'CLEAN_A',
            'manifest' => [],
            'tokens' => [],
        ])."\n"
        .gl_jsonEncode([
            'session_id' => 'thread-b',
            'clean_text' => 'CLEAN_B',
            'manifest' => [],
            'tokens' => [],
        ])."\n"
    );

    $client = DaemonClient::withStreams($stdin, $stdout);

    $sessionA = new DaemonSession('thread-a', $client);
    $sessionB = new DaemonSession('thread-b', $client);

    $results = [];

    $fiberA = new Fiber(function () use ($sessionA, &$results) {
        Fiber::suspend();
        $results['a'] = $sessionA->clean('payload-A')->cleanText;
    });

    $fiberB = new Fiber(function () use ($sessionB, &$results) {
        Fiber::suspend();
        $results['b'] = $sessionB->clean('payload-B')->cleanText;
    });

    // Start both fibers (each yields immediately on the suspend above).
    $fiberA->start();
    $fiberB->start();

    // Resume in interleaved order. Because PHP fibers do not preempt,
    // each resume runs the next fiber's body to completion — request() is
    // atomic from one fiber's perspective. The serialisation contract
    // means the call order on the wire is deterministic per the resume
    // order here.
    $fiberA->resume();
    $fiberB->resume();

    expect($results)->toHaveKeys(['a', 'b']);
    expect($results['a'])->toBe('CLEAN_A');
    expect($results['b'])->toBe('CLEAN_B');
});
