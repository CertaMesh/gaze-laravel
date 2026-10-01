<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use Illuminate\Support\Facades\Process;

/*
 * Gaze::probeCleanWarnings() (@internal, gaze:doctor #159) must run exactly
 * what clean() runs, so a doctor verdict holds for production cleans.
 */

it('runs the argv clean() builds, minus --audit-db only', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
        ], JSON_THROW_ON_ERROR)),
    ]);

    $gaze = $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        maxBytes: 4096,
        sessionTtlSeconds: 600,
        auditDbPath: '/var/lib/gaze/audit.sqlite',
        locale: 'de-DE',
        rulepacks: ['core', 'secrets'],
        rulepackPaths: ['/etc/gaze/extra.toml'],
        safetyNet: true,
        safetyNetTimeoutMs: 7500,
        safetyNetMode: 'redact',
        safetyNetBackend: 'nym',
        safetyNetFallback: 'strict',
        sessionScope: 'conversation',
        nerThreshold: 0.6,
    );

    $gaze->clean('Hello Alice');
    $gaze->probeCleanWarnings('gaze doctor probe');

    /** @var list<list<string>> $commands */
    $commands = [];
    Process::assertRanTimes(function ($process) use (&$commands): bool {
        $commands[] = array_values(array_map('strval', (array) $process->command));

        return true;
    }, 2);
    $clean = $commands[0] ?? [];
    $probe = $commands[1] ?? [];
    $expectedProbe = array_values(array_filter(
        $clean,
        fn (string $arg): bool => ! str_starts_with($arg, '--audit-db='),
    ));

    expect($clean)->toContain('--audit-db=/var/lib/gaze/audit.sqlite')
        ->and($probe)->toBe($expectedProbe);
});

it('returns only the warning: and notice: lines of a successful clean', function () {
    Process::fake([
        '*' => Process::result(
            output: '{"clean_text":"gaze doctor probe","session_blob":"YmxvYg=="}',
            errorOutput: "warning: class generalize is one-way (no restore token) for: email.\r\n"
                ."{\"warning\":\"SafetyNet\",\"variant\":\"ClassMismatch\",\"count\":1}\n"
                ."  notice: core rulepack floor is off  \n"
                ."unrelated: line\n\n",
        ),
    ]);

    expect($this->makeGaze()->probeCleanWarnings('gaze doctor probe'))->toBe([
        'warning: class generalize is one-way (no restore token) for: email.',
        'notice: core rulepack floor is off',
    ]);
});

it('returns no lines when the clean prints none', function () {
    Process::fake(['*' => Process::result(output: '{}')]);

    expect($this->makeGaze()->probeCleanWarnings('gaze doctor probe'))->toBe([]);
});

it('throws the typed exception when the clean fails', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '{"error":"PolicyConfig","exit":2}', exitCode: 2),
    ]);

    $this->makeGaze()->probeCleanWarnings('gaze doctor probe');
})->throws(GazeException::class);

it('applies the clean() pre-flight guards without spawning', function () {
    Process::fake();

    try {
        $this->makeGaze(sessionScope: 'ephemeral')->probeCleanWarnings('gaze doctor probe');
        $this->fail('expected the session-scope guard to throw');
    } catch (GazePolicyConfigDetailException) {
        Process::assertNothingRan();
    }
});
