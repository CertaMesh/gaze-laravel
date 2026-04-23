<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Tests\TestCase;

final class CheckCommandTest extends TestCase
{
    public function test_check_reports_ok_when_binary_and_version_succeed(): void
    {
        $this->app->instance(
            BinaryResolver::class,
            new BinaryResolver(explicitPath: '/fake/ghostwriter', vendorBinPath: '/none'),
        );
        Process::fake([
            '*' => Process::result(output: "ghostwriter 0.1.0\n"),
        ]);

        $this->artisan('gaze:check')
            ->assertExitCode(0)
            ->expectsOutputToContain('/fake/ghostwriter')
            ->expectsOutputToContain('ghostwriter 0.1.0')
            ->expectsOutputToContain('OK');
    }

    public function test_check_fails_when_binary_missing(): void
    {
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
    }

    public function test_check_fails_when_version_invocation_errors(): void
    {
        $this->app->instance(
            BinaryResolver::class,
            new BinaryResolver(explicitPath: '/fake/ghostwriter', vendorBinPath: '/none'),
        );
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $this->artisan('gaze:check')
            ->assertExitCode(1)
            ->expectsOutputToContain('FAIL');
    }
}
