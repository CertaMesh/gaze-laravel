<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigException;
use CertaMesh\Gaze\Exceptions\GazePolicyOpenException;
use CertaMesh\Gaze\PolicyFile;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\SessionScopeGuard;
use Illuminate\Support\Facades\Process;

/*
 * #182: a policy `[session] scope = "ephemeral"` (no GAZE_SESSION_SCOPE
 * override) made the binary answer every clean with the Retryable Pipeline
 * error, so queue jobs retried forever. Gaze::clean() now reads the policy
 * scope (cached by stat fingerprint) and refuses it before spawning.
 */

beforeEach(function () {
    PolicyFile::flushCache();
    $this->dir = sys_get_temp_dir().'/gaze-psg-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $path) {
        @chmod($path, 0644);
    }
    gl_recursiveRemove($this->dir);
});

/**
 * Write a throwaway policy whose `[session]` block holds $sessionLine verbatim.
 */
function psg_policy(string $dir, string $sessionLine): string
{
    $path = $dir.'/policy-'.bin2hex(random_bytes(4)).'.toml';
    file_put_contents($path, "[session]\n{$sessionLine}\n\n[[rule]]\nkind = \"default\"\naction = \"tokenize\"\n");

    return $path;
}

function psg_fakeCleanSuccess(): void
{
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);
}

it('refuses a policy-level ephemeral scope before spawning, non-retryable', function (?string $override) {
    Process::fake();
    $policy = psg_policy($this->dir, 'scope = "ephemeral"');

    try {
        $this->makeGaze(policyPath: $policy, sessionScope: $override)->clean('Hello Alice');
    } catch (GazePolicyConfigDetailException $e) {
        expect($e->getMessage())->toBe(SessionScopeGuard::POLICY_EPHEMERAL_UNSUPPORTED)
            ->not->toContain('Alice')
            ->and($e->detail())->toContain('ephemeral')
            ->and($e->exitCode)->toBe(2)
            ->and($e->stderrHash)->toBeNull()
            // The binary's own answer (Pipeline, exit 3) is Retryable.
            ->and(GazeRetryPolicy::classify($e))->toBe(RetryAction::Fail);

        Process::assertNothingRan();

        return;
    }

    $this->fail('Expected GazePolicyConfigDetailException to be thrown.');
})->with([
    'no override' => [null],
    'empty override (unset env var)' => [''],
]);

it('refuses a policy-level ephemeral scope on mask() too', function () {
    Process::fake();

    expect(fn () => $this->makeGaze(policyPath: psg_policy($this->dir, 'scope = "ephemeral"'))->mask('Hello'))
        ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::POLICY_EPHEMERAL_UNSUPPORTED);

    Process::assertNothingRan();
});

it('lets a conversation or persistent override win over an ephemeral policy', function (string $override) {
    psg_fakeCleanSuccess();

    $session = $this->makeGaze(policyPath: psg_policy($this->dir, 'scope = "ephemeral"'), sessionScope: $override)
        ->clean('Hello Alice');

    expect($session->cleanText)->toBe('Hello Name_1');
    Process::assertRan(fn ($process): bool => in_array('--session-scope='.$override, $process->command, true));
})->with(['conversation', 'persistent']);

it('reports an ephemeral override as the override, not the policy', function () {
    Process::fake();

    expect(fn () => $this->makeGaze(policyPath: psg_policy($this->dir, 'scope = "persistent"'), sessionScope: 'ephemeral')->clean('Hello'))
        ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::EPHEMERAL_UNSUPPORTED);

    Process::assertNothingRan();
});

it('spawns normally for a non-ephemeral or absent policy scope', function (string $sessionLine) {
    psg_fakeCleanSuccess();

    $this->makeGaze(policyPath: psg_policy($this->dir, $sessionLine))->clean('Hello Alice');

    Process::assertRanTimes(fn (): bool => true, 1);
})->with([
    'persistent' => ['scope = "persistent"'],
    'conversation' => ['scope = "conversation"'],
    'no scope key' => ['ttl_secs = 60'],
    // Upstream matches exactly: "Ephemeral" is an invalid value the binary
    // rejects with its own PolicyConfig detail, not the ephemeral scope.
    'wrong case' => ['scope = "Ephemeral"'],
    'non-string scope' => ['scope = 1'],
]);

it('lets the binary report a missing policy itself', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '{"error":"PolicyOpen","exit":4}', exitCode: 4),
    ]);

    expect(fn () => $this->makeGaze(policyPath: sys_get_temp_dir().'/gaze-psg-missing-'.bin2hex(random_bytes(4)).'.toml')->clean('Hello'))
        ->toThrow(GazePolicyOpenException::class);

    Process::assertRanTimes(fn (): bool => true, 1);
});

it('lets the binary report an unparseable policy itself', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '{"error":"PolicyConfig","exit":2}', exitCode: 2),
    ]);

    $policy = $this->dir.'/broken.toml';
    file_put_contents($policy, "[session\nscope = \"ephemeral\"\n");

    expect(fn () => $this->makeGaze(policyPath: $policy)->clean('Hello'))
        ->toThrow(GazePolicyConfigException::class);

    Process::assertRanTimes(fn (): bool => true, 1);
});

it('lets the binary report an unreadable policy itself', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root reads mode-000 files.');
    }

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '{"error":"PolicyOpen","exit":4}', exitCode: 4),
    ]);

    $policy = psg_policy($this->dir, 'scope = "ephemeral"');
    chmod($policy, 0000);

    expect(fn () => $this->makeGaze(policyPath: $policy)->clean('Hello'))
        ->toThrow(GazePolicyOpenException::class);

    Process::assertRanTimes(fn (): bool => true, 1);
});

it('caches the parsed scope per stat fingerprint and re-reads on an mtime change', function () {
    psg_fakeCleanSuccess();

    // Same byte length on purpose, rewritten in place (same inode): only the
    // mtime tells the two versions apart.
    $policy = psg_policy($this->dir, 'scope = "persistent"');
    $mtime = time() - 120;
    touch($policy, $mtime);

    $gaze = $this->makeGaze(policyPath: $policy);
    $gaze->clean('Hello Alice');

    file_put_contents($policy, str_replace('scope = "persistent"', 'scope = "ephemeral" ', (string) file_get_contents($policy)));
    touch($policy, $mtime);
    clearstatcache(true, $policy);
    expect(filesize($policy))->toBe(strlen("[session]\nscope = \"persistent\"\n\n[[rule]]\nkind = \"default\"\naction = \"tokenize\"\n"));

    // Unchanged fingerprint: the cached parse answers, no TOML re-read.
    $gaze->clean('Hello Alice');
    Process::assertRanTimes(fn (): bool => true, 2);

    // A new mtime invalidates the entry, so the ephemeral scope is seen
    // without restarting the worker.
    touch($policy, $mtime + 60);

    expect(fn () => $gaze->clean('Hello Alice'))
        ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::POLICY_EPHEMERAL_UNSUPPORTED);
    Process::assertRanTimes(fn (): bool => true, 2);

    // And back: switching the policy away from ephemeral is picked up too.
    file_put_contents($policy, str_replace('scope = "ephemeral" ', 'scope = "persistent"', (string) file_get_contents($policy)));
    touch($policy, $mtime + 120);

    $gaze->clean('Hello Alice');
    Process::assertRanTimes(fn (): bool => true, 3);
});

it('does not cache a failed read, so a permissions fix takes effect at once', function () {
    $path = tempnam(sys_get_temp_dir(), 'gaze-policy-').'.toml';
    file_put_contents($path, "[session]\nscope = \"ephemeral\"\n");
    chmod($path, 0000);

    try {
        if (is_readable($path)) {
            $this->markTestSkipped('running as a user that can read mode-0000 files (root)');
        }

        expect(PolicyFile::sessionScope($path))->toBeNull();

        chmod($path, 0644);

        expect(PolicyFile::sessionScope($path))->toBe('ephemeral');
    } finally {
        @chmod($path, 0644);
        @unlink($path);
    }
});

it('skips the TOML parse when the policy cannot name ephemeral', function () {
    $path = tempnam(sys_get_temp_dir(), 'gaze-policy-').'.toml';
    // Invalid TOML on purpose: a parse would fail, the cheap negative never parses.
    file_put_contents($path, "[session]\nscope = \"persistent\"\n[[[broken");

    try {
        expect(PolicyFile::sessionScope($path))->toBeNull();
    } finally {
        @unlink($path);
    }
});
