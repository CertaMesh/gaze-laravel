<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\EncryptedBlob;
use Naoray\GazeLaravel\GazeSession;

it('reports doctor readiness without deep round-trip', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../policy.toml.example');

    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $this->artisan('gaze:doctor')
        ->assertExitCode(0)
        ->expectsOutputToContain('status')
        ->expectsOutputToContain('OK');
});

it('runs the deep round-trip check when requested', function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../policy.toml.example');

    Process::fake([
        '*' => Process::result(output: "gaze 0.3.0-rc.3\n"),
    ]);

    $clean = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::wrap(base64_encode(json_encode([
            'text' => 'doctor@example.com',
        ], JSON_THROW_ON_ERROR))),
        detections: 1,
    );

    $this->bindScriptedGaze($clean, 'doctor@example.com');

    $this->artisan('gaze:doctor', ['--deep' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('deep')
        ->expectsOutputToContain('OK');
});
