<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Daemon\DaemonServeCommand;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Symfony\Component\Process\Process;

/**
 * Signal-forward contract: the installSignalForwarders() handler invokes
 * `$invoked->signal($signal)` so SIGTERM from the supervisor reaches the
 * child's graceful-shutdown loop. Avoiding orphaned children depends on
 * this hand-off — the streaming wait loop only exits when the child does.
 */
it('forwards SIGTERM to the child via the installed pcntl handler', function () {
    if (! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
        $this->markTestSkipped('pcntl extension not available');
    }

    /** @var array<int, int> $signalsReceived */
    $signalsReceived = [];

    $invoked = new class($signalsReceived) implements InvokedProcess
    {
        /**
         * @param  array<int, int>  $signals
         */
        public function __construct(public array &$signals) {}

        public function id()
        {
            return 9999;
        }

        public function signal(int $signal)
        {
            $this->signals[] = $signal;

            return $this;
        }

        public function running()
        {
            return true;
        }

        public function output()
        {
            return '';
        }

        public function errorOutput()
        {
            return '';
        }

        public function latestOutput()
        {
            return '';
        }

        public function latestErrorOutput()
        {
            return '';
        }

        public function wait(?callable $output = null)
        {
            return new ProcessResult(
                new Process(['true'])
            );
        }

        public function waitUntil(?callable $output = null)
        {
            return new ProcessResult(
                new Process(['true'])
            );
        }
    };

    $command = new DaemonServeCommand;

    $reflection = new ReflectionMethod($command, 'installSignalForwarders');
    $reflection->setAccessible(true);
    $reflection->invoke($command, $invoked);

    $handler = pcntl_signal_get_handler(SIGTERM);
    expect(is_callable($handler))->toBeTrue();

    if (is_callable($handler)) {
        $handler(SIGTERM);
    }

    expect($signalsReceived)->toBe([SIGTERM]);

    // Reset to default so PHPUnit's own SIGTERM handling is not stomped on.
    pcntl_signal(SIGTERM, SIG_DFL);
    pcntl_signal(SIGINT, SIG_DFL);
});

it('returns the child exit code without orphaning the child', function () {
    Illuminate\Support\Facades\Process::fake([
        '*' => Illuminate\Support\Facades\Process::result(output: '', exitCode: 143),
    ]);

    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    config()->set('gaze.daemon.policy_path', '/etc/gaze/policy.toml');

    // Exit 143 = SIGTERM convention; verifies the wrapper returns whatever
    // the child returned rather than mapping to a constant.
    $this->artisan('gaze:daemon:serve')->assertExitCode(143);
});
