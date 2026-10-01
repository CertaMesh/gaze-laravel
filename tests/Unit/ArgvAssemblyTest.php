<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\SafetyNetBackendGuard;
use CertaMesh\Gaze\SessionScopeGuard;
use Illuminate\Support\Facades\Process;

it('assembles clean argv with policy and json output', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello Alice');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
        ])
            ->and($process->input)->toBe('Hello Alice');

        return true;
    });
});

it('assembles restore argv and sends the session envelope', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);

    $this->makeGaze(maxBytes: 1024)->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'restore',
            '--format=json',
            '--max-bytes=1024',
        ]);

        $payload = json_decode($process->input ?? '', true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->toBe([
            'session_blob' => 'blob',
            'text' => 'Hello Name_1',
        ]);

        return true;
    });
});

it('forwards --restore-mode when configured', function (string $restoreMode) {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    $this->makeGaze(restoreMode: $restoreMode)->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process) use ($restoreMode): bool {
        expect($process->command)->toContain('--restore-mode='.$restoreMode);

        return true;
    });
})->with(['strict', 'tolerant']);

it('omits --restore-mode when not configured', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    $this->makeGaze()->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--restore-mode=');
        }

        return true;
    });
});

it('omits --telemetry and a telemetry --audit-db when restore_telemetry is off (default)', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    // audit_db_path is set, but telemetry off must NOT forward it via restore().
    $this->makeGaze(auditDbPath: '/var/lib/gaze/audit.sqlite')->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'restore',
            '--format=json',
        ]);

        return true;
    });
});

it('forwards --telemetry and --audit-db when restore_telemetry is on and audit_db_path is set', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    $this->makeGaze(auditDbPath: '/var/lib/gaze/audit.sqlite', restoreTelemetry: true)
        ->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--telemetry')
            ->toContain('--audit-db=/var/lib/gaze/audit.sqlite');

        return true;
    });
});

it('forwards --telemetry but omits --audit-db when restore_telemetry is on with no audit_db_path', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    $this->makeGaze(restoreTelemetry: true)->restore($session, 'Hello Name_1');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--telemetry');
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--audit-db=');
        }

        return true;
    });
});

it('forwards --audit-db when configured on clean argv', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        auditDbPath: '/tmp/audit.sqlite',
    )->clean('Hello Alice');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--audit-db=/tmp/audit.sqlite',
        ]);

        return true;
    });
});

it('omits --audit-db when audit_db_path is null', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->not->toContain('--audit-db');
        foreach ($process->command as $arg) {
            expect($arg)->not->toStartWith('--audit-db=');
        }

        return true;
    });
});

it('forwards --locale when configured', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', locale: 'en-GB')->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--locale=en-GB');

        return true;
    });
});

it('forwards --session-scope when configured', function (string $sessionScope) {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', sessionScope: $sessionScope)->clean('Hello');

    Process::assertRan(function ($process) use ($sessionScope): bool {
        expect($process->command)->toContain('--session-scope='.$sessionScope);

        return true;
    });
})->with(['conversation', 'persistent']);

it('refuses an ephemeral session scope before spawning, regardless of case and whitespace', function (string $sessionScope) {
    Process::fake();

    try {
        $this->makeGaze(policyPath: '/tmp/policy.toml', sessionScope: $sessionScope)->clean('Hello');
    } catch (GazePolicyConfigDetailException $e) {
        expect($e->getMessage())->toBe(SessionScopeGuard::EPHEMERAL_UNSUPPORTED)
            ->not->toContain('Hello')
            ->and($e->detail())->toContain('ephemeral')
            ->and($e->exitCode)->toBe(2)
            ->and($e->stderrHash)->toBeNull()
            // The binary's own answer (Pipeline, exit 3) is Retryable; the
            // pre-flight must land in the fail lane instead.
            ->and(GazeRetryPolicy::classify($e))->toBe(RetryAction::Fail);

        Process::assertNothingRan();

        return;
    }

    $this->fail('Expected GazePolicyConfigDetailException to be thrown.');
})->with(['ephemeral', 'EPHEMERAL', ' Ephemeral ']);

it('refuses an ephemeral session scope on mask() too, before spawning', function () {
    Process::fake();

    expect(fn () => $this->makeGaze(policyPath: '/tmp/policy.toml', sessionScope: 'ephemeral')->mask('Hello'))
        ->toThrow(GazePolicyConfigDetailException::class, SessionScopeGuard::EPHEMERAL_UNSUPPORTED);

    Process::assertNothingRan();
});

it('produces multiple --rulepack-bundled entries', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', rulepacks: ['names', 'emails'])->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--rulepack-bundled=names')
            ->toContain('--rulepack-bundled=emails');

        return true;
    });
});

it('appends --safety-net=openai-filter when safety_net is true', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: true)->clean('Hello');

    Process::assertRan(function ($process): bool {
        // PR #48 originally emitted bare --safety-net which v0.6.x binary rejects.
        expect($process->command)->toContain('--safety-net=openai-filter');

        return true;
    });
});

it('appends --openai-filter-device when safety_net_device is set', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNetDevice: 'cuda:0')->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toContain('--openai-filter-device=cuda:0');

        return true;
    });
});

it('appends OpenAI privacy-filter argv flags when configured', function (string $parameter, mixed $value, string $expectedFlag) {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(...[
        'policyPath' => '/tmp/policy.toml',
        $parameter => $value,
    ])->clean('Hello');

    Process::assertRan(function ($process) use ($expectedFlag): bool {
        expect($process->command)->toContain($expectedFlag);

        return true;
    });
})->with([
    'openai filter command' => ['openaiFilterCommand', '/usr/local/bin/opf', '--openai-filter-command=/usr/local/bin/opf'],
    'openai filter checkpoint' => ['openaiFilterCheckpoint', '/models/opf', '--openai-filter-checkpoint=/models/opf'],
    'openai filter operating point' => ['openaiFilterOperatingPoint', 'high-recall', '--openai-filter-operating-point=high-recall'],
    'safety net timeout' => ['safetyNetTimeoutMs', 7500, '--safety-net-timeout-ms=7500'],
    'safety net input limit' => ['safetyNetInputLimitBytes', 123456, '--safety-net-input-limit-bytes=123456'],
    'safety net mode' => ['safetyNetMode', 'tolerant', '--safety-net-mode=tolerant'],
    'safety net fallback' => ['safetyNetFallback', 'redact', '--safety-net-fallback=redact'],
]);

it('forwards --safety-net-backend right after the enable switch when the net is enabled', function (string $backend) {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: true, safetyNetBackend: $backend)->clean('Hello');

    Process::assertRan(function ($process) use ($backend): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--safety-net=openai-filter',
            "--safety-net-backend={$backend}",
        ]);

        return true;
    });
})->with(['openai-filter', 'nym']);

it('omits --safety-net-backend when the net is disabled (gaze >= 0.15 rejects a lone selector)', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    // The natural "turn the net off" state: GAZE_SAFETY_NET=false with the
    // backend gaze:install:safety-net wrote still in .env. Only the selector
    // is gated — the other sub-options keep forwarding as before.
    $this->makeGaze(
        policyPath: '/tmp/policy.toml',
        safetyNet: false,
        safetyNetBackend: 'openai-filter',
        safetyNetMode: 'strict',
    )->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
            '--safety-net-mode=strict',
        ]);

        return true;
    });
});

it('omits locale, rulepacks, safety-net flags when not configured', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml')->clean('Hello');

    Process::assertRan(function ($process): bool {
        foreach ($process->command as $arg) {
            expect($arg)
                ->not->toStartWith('--locale=')
                ->not->toStartWith('--session-scope=')
                ->not->toStartWith('--rulepack-bundled=')
                ->not->toStartWith('--rulepack-path=')
                ->not->toStartWith('--safety-net')
                ->not->toStartWith('--openai-filter-device=')
                ->not->toStartWith('--openai-filter-command=')
                ->not->toStartWith('--openai-filter-checkpoint=')
                ->not->toStartWith('--openai-filter-operating-point=')
                ->not->toStartWith('--kiji-');
        }

        return true;
    });
});

it('refuses an enabled kiji-distilbert backend before spawning (removed upstream in gaze 0.15.0)', function () {
    Process::fake();

    try {
        $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: true, safetyNetBackend: 'kiji-distilbert')->clean('Hello');
    } catch (GazeSafetyNetConfigException $e) {
        expect($e->getMessage())->toBe(SafetyNetBackendGuard::KIJI_DISTILBERT_REMOVED)
            ->toContain('removed upstream in gaze 0.15.0')
            ->toContain('nym')
            ->not->toContain('Hello')
            ->and($e->exitCode)->toBe(2)
            ->and($e->stderrHash)->toBeNull();

        Process::assertNothingRan();

        return;
    }

    $this->fail('Expected GazeSafetyNetConfigException to be thrown.');
});

it('refuses an enabled kiji-distilbert backend on mask() too, before spawning', function () {
    Process::fake();

    expect(fn () => $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: true, safetyNetBackend: 'kiji-distilbert')->mask('Hello'))
        ->toThrow(GazeSafetyNetConfigException::class, 'removed upstream in gaze 0.15.0');

    Process::assertNothingRan();
});

it('lets a disabled net with a leftover kiji-distilbert selector clean without the selector', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->makeGaze(policyPath: '/tmp/policy.toml', safetyNet: false, safetyNetBackend: 'kiji-distilbert')->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe([
            '/fake/gaze',
            'clean',
            '--policy=/tmp/policy.toml',
            '--format=json',
        ]);

        return true;
    });
});

it('keeps restore working when the safety net still selects kiji-distilbert', function () {
    Process::fake([
        '*' => Process::result(output: json_encode(['text' => 'Hello Alice'], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1);
    $restored = $this->makeGaze(safetyNet: true, safetyNetBackend: 'kiji-distilbert')->restore($session, 'Hello Name_1');

    expect($restored)->toBe('Hello Alice');
    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(['/fake/gaze', 'restore', '--format=json']);

        return true;
    });
});
