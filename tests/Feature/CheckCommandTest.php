<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;

it('reports OK when binary and version succeed', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $this->artisan('gaze:check')
        ->assertExitCode(0)
        ->expectsOutputToContain('/fake/gaze')
        ->expectsOutputToContain('gaze 0.3.0-rc.3')
        ->expectsOutputToContain('OK');
});

it('fails when binary is missing', function () {
    $emptyDir = sys_get_temp_dir().'/gaze-laravel-empty-'.bin2hex(random_bytes(6));
    mkdir($emptyDir, 0755, true);

    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: null, vendorBinPath: $emptyDir.'/nope'),
    );

    $originalPath = getenv('PATH');
    putenv('PATH='.$emptyDir);
    try {
        $this->artisan('gaze:check')
            ->assertExitCode(1)
            ->expectsOutputToContain('FAIL');
    } finally {
        putenv('PATH='.($originalPath === false ? '' : $originalPath));
        @rmdir($emptyDir);
    }
});

it('fails when version invocation errors', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $this->artisan('gaze:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('FAIL');
});

it('fails when the dedicated encryption key is invalid', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('gaze.blob_encryption_key', 'not-base64-32-bytes');

    $this->artisan('gaze:check')
        ->assertExitCode(1)
        ->expectsOutputToContain('invalid')
        ->expectsOutputToContain('FAIL');
});
