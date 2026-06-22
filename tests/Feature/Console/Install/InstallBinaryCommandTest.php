<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Install\InstallBinaryCommand;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryDownloadOptions;
use CertaMesh\Gaze\Install\BinaryDownloadResult;
use CertaMesh\Gaze\Install\BinaryDownloadStatus;

/** Downloader stub that returns a fixed status and never touches the network. */
function ibc_fakeDownloader(BinaryDownloadStatus $status): BinaryDownloader
{
    return new class($status) extends BinaryDownloader
    {
        public function __construct(private BinaryDownloadStatus $status) {}

        public function install(BinaryDownloadOptions $opts, ?Closure $emit = null): BinaryDownloadResult
        {
            if ($emit !== null) {
                $emit('info', 'fake downloader');
            }

            return new BinaryDownloadResult($this->status, $opts->binDir.'/gaze', BinaryDownloader::PINNED_VERSION, 'fake');
        }
    };
}

/**
 * Bind a resolver whose binary never runs (forces the download path). An
 * explicit bogus path short-circuits BinaryResolver::resolve() before its PATH
 * fallback, so a real `gaze` on the dev machine's PATH cannot leak in; the
 * command's `--version` probe then fails and treats it as "no binary".
 */
function ibc_bindNoBinaryResolver(): void
{
    app()->instance(BinaryResolver::class, new BinaryResolver(
        explicitPath: '/nonexistent/gaze-'.bin2hex(random_bytes(4)),
        vendorBinPath: '/nonexistent/gaze',
    ));
}

/** Bind a resolver pointing at a runnable fake gaze binary; returns the path. */
function ibc_bindRunnableResolver(): string
{
    $bin = sys_get_temp_dir().'/gaze-fake-'.bin2hex(random_bytes(6));
    file_put_contents($bin, "#!/bin/sh\necho 'gaze 9.9.9'\n");
    chmod($bin, 0755);
    app()->instance(BinaryResolver::class, new BinaryResolver(explicitPath: $bin, vendorBinPath: '/nonexistent/gaze'));

    return $bin;
}

it('does not expose the dropped --dest or --release flags (CB7)', function () {
    $definition = $this->app->make(InstallBinaryCommand::class)->getDefinition();

    expect($definition->hasOption('force'))->toBeTrue();
    expect($definition->hasOption('dest'))->toBeFalse();
    expect($definition->hasOption('release'))->toBeFalse();
});

it('exits 0 when the downloader reports already satisfied', function () {
    ibc_bindNoBinaryResolver();
    app()->instance(BinaryDownloader::class, ibc_fakeDownloader(BinaryDownloadStatus::AlreadySatisfied));

    $this->artisan('gaze:install:binary')->assertExitCode(0);
});

it('exits 0 when the downloader installs the binary', function () {
    ibc_bindNoBinaryResolver();
    app()->instance(BinaryDownloader::class, ibc_fakeDownloader(BinaryDownloadStatus::Installed));

    $this->artisan('gaze:install:binary')->assertExitCode(0);
});

it('exits non-zero on unsupported platform when no binary resolves', function () {
    ibc_bindNoBinaryResolver();
    app()->instance(BinaryDownloader::class, ibc_fakeDownloader(BinaryDownloadStatus::UnsupportedPlatform));

    $this->artisan('gaze:install:binary')->assertFailed();
});

it('exits non-zero when the download fails outright', function () {
    ibc_bindNoBinaryResolver();
    app()->instance(BinaryDownloader::class, ibc_fakeDownloader(BinaryDownloadStatus::Failed));

    $this->artisan('gaze:install:binary')->assertFailed();
});

it('forwards --force to the downloader options', function () {
    ibc_bindNoBinaryResolver();
    $spy = new class extends BinaryDownloader
    {
        public ?BinaryDownloadOptions $seen = null;

        public function install(BinaryDownloadOptions $opts, ?Closure $emit = null): BinaryDownloadResult
        {
            $this->seen = $opts;

            return new BinaryDownloadResult(BinaryDownloadStatus::Installed, $opts->binDir.'/gaze', BinaryDownloader::PINNED_VERSION, 'ok');
        }
    };
    app()->instance(BinaryDownloader::class, $spy);

    $this->artisan('gaze:install:binary --force')->assertExitCode(0);

    expect($spy->seen)->not->toBeNull();
    expect($spy->seen?->force)->toBeTrue();
});

it('skips with guidance and exits 0 on unsupported platform when a binary already resolves (CB5)', function () {
    $bin = ibc_bindRunnableResolver();
    // --force bypasses the early resolver short-circuit so we reach the downloader,
    // which reports UnsupportedPlatform; the command must still succeed because a
    // working binary is already resolvable.
    app()->instance(BinaryDownloader::class, ibc_fakeDownloader(BinaryDownloadStatus::UnsupportedPlatform));

    $this->artisan('gaze:install:binary --force')
        ->expectsOutputToContain('build from source')
        ->assertExitCode(0);

    @unlink($bin);
});

it('short-circuits to success without downloading when a runnable binary already resolves (CB5)', function () {
    $bin = ibc_bindRunnableResolver();
    $spy = new class extends BinaryDownloader
    {
        public bool $called = false;

        public function install(BinaryDownloadOptions $opts, ?Closure $emit = null): BinaryDownloadResult
        {
            $this->called = true;

            return new BinaryDownloadResult(BinaryDownloadStatus::Installed, $opts->binDir.'/gaze', BinaryDownloader::PINNED_VERSION, 'ok');
        }
    };
    app()->instance(BinaryDownloader::class, $spy);

    $this->artisan('gaze:install:binary')->assertExitCode(0);

    expect($spy->called)->toBeFalse();

    @unlink($bin);
});
