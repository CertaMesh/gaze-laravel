<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Exceptions\GazeRestoreFailedException;
use Naoray\GazeLaravel\Exceptions\GazeSanitizeFailedException;

it('throws on sanitize failure when fail_closed=true (default)', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $this->makeGaze(failClosed: true)->sanitize('secret text');
})->throws(GazeSanitizeFailedException::class);

it('returns fallback session with original text when fail_closed=false on sanitize', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $session = $this->makeGaze(failClosed: false)->sanitize('hi alice');

    expect($session->cleanText)->toBe('hi alice')
        ->and($session->sessionBlob)->toBe('')
        ->and($session->placeholders)->toBe([])
        ->and($session->warnings)->toBe(['gaze-sanitize-failed-fail-open']);
});

it('throws on restore failure when fail_closed=true (default)', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $this->makeGaze(failClosed: true)->restore('text with <TOKEN>', 'blob');
})->throws(GazeRestoreFailedException::class);

it('returns fallback with original text when fail_closed=false on restore', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $restored = $this->makeGaze(failClosed: false)->restore('text with <TOKEN>', 'blob');

    expect($restored->text)->toBe('text with <TOKEN>')
        ->and($restored->warnings)->toBe(['gaze-restore-failed-fail-open']);
});

it('logs a distinct fail-open bypass warning without leaking stderr', function () {
    $stderr = 'SECRET: internal state dump';
    Process::fake([
        '*' => Process::result(output: '', errorOutput: $stderr, exitCode: 1),
    ]);

    $captured = [];
    Log::shouldReceive('warning')
        ->andReturnUsing(function (string $message, array $context) use (&$captured): void {
            $captured[] = ['message' => $message, 'context' => $context];
        });

    $this->makeGaze(failClosed: false)->sanitize('x');

    $bypass = collect($captured)->firstWhere('message', 'gaze sanitize fail-open bypass');
    expect($bypass)->not->toBeNull()
        ->and($bypass['context']['reason'])->toBe('fail_closed_disabled')
        ->and($bypass['context']['stderr_sha256'])->toMatch('/^[a-f0-9]{64}$/');

    expect(json_encode($captured, JSON_THROW_ON_ERROR))->not->toContain($stderr);
});

it('default fail_closed is true when omitted from constructor', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    // makeGaze default is failClosed=true; confirm via explicit unset omission semantics.
    $this->makeGaze()->sanitize('x');
})->throws(GazeSanitizeFailedException::class);

it('reads fail_closed from the container config via service provider', function () {
    config()->set('gaze.fail_closed', false);

    $this->app->forgetInstance(\Naoray\GazeLaravel\Gaze::class);
    $gaze = $this->app->make(\Naoray\GazeLaravel\Gaze::class);

    // Point the resolver at a fake path so we hit the failure path deterministically.
    $this->app->instance(
        \Naoray\GazeLaravel\BinaryResolver::class,
        new \Naoray\GazeLaravel\BinaryResolver(
            explicitPath: '/fake/ghostwriter',
            vendorBinPath: '/nonexistent',
        ),
    );
    $this->app->forgetInstance(\Naoray\GazeLaravel\Gaze::class);
    $gaze = $this->app->make(\Naoray\GazeLaravel\Gaze::class);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $session = $gaze->sanitize('x');

    expect($session->warnings)->toBe(['gaze-sanitize-failed-fail-open']);
});
