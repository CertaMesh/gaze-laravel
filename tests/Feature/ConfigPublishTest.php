<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('publishes policy.toml via the gaze-policy tag', function () {
    $target = base_path('policy.toml');
    @unlink($target);

    try {
        Artisan::call('vendor:publish', [
            '--tag' => 'gaze-policy',
            '--force' => true,
        ]);

        expect($target)->toBeFile();
    } finally {
        @unlink($target);
    }
});

it('publishes config to application config path', function () {
    $target = $this->app->configPath('gaze.php');
    @unlink($target);

    Artisan::call('vendor:publish', [
        '--tag' => 'gaze-config',
        '--force' => true,
    ]);

    expect($target)->toBeFile();

    $published = require $target;
    expect($published)->toBeArray()
        ->toHaveKeys(['binary', 'timeout_seconds', 'policy_path', 'blob_encryption_key', 'audit_db_path']);

    expect($published)->toHaveKey('proxy');
    expect($published['proxy'])->toBeArray()
        ->toHaveKeys(['bind', 'session_ttl', 'rulepack', 'policy_path', 'upstream', 'stop_timeout']);
    expect($published['proxy']['upstream'])->toBeArray()
        ->toHaveKeys(['openai', 'anthropic', 'gemini']);

    expect($published)->toHaveKey('daemon');
    expect($published['daemon'])->toBeArray()
        ->toHaveKeys([
            'policy_path',
            'audit_db_path',
            'request_timeout_ms',
            'idle_timeout_s',
            'binary_path',
            'stderr_path',
        ]);
    expect($published['daemon']['policy_path'])->toBeNull();
    expect($published['daemon']['audit_db_path'])->toBeNull();
    expect($published['daemon']['idle_timeout_s'])->toBeNull();
    expect($published['daemon']['binary_path'])->toBeNull();
    expect($published['daemon']['stderr_path'])->toBeNull();
    expect((int) $published['daemon']['request_timeout_ms'])->toBe(5000);

    @unlink($target);
});
