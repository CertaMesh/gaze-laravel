<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryDownloadOptions;
use CertaMesh\Gaze\Install\BinaryDownloadStatus;
use CertaMesh\Gaze\Install\BinaryInstaller;
use Composer\IO\BufferIO;

it('detects a supported target triple or null', function () {
    expect(BinaryDownloader::detectTarget())->toBeIn([
        'aarch64-apple-darwin',
        'x86_64-apple-darwin',
        'x86_64-linux-gnu',
        'aarch64-unknown-linux-gnu',
        null,
    ]);
});

it('requires a checksum entry for the asset', function () {
    $base = sys_get_temp_dir().'/gaze-dl-'.bin2hex(random_bytes(4));
    $tar = $base.'.tar';
    $sums = $base.'.sha256';
    file_put_contents($tar, 'x');
    file_put_contents($sums, "deadbeef  other-asset\n");

    try {
        expect(fn () => BinaryDownloader::verifyChecksum($tar, $sums, 'gaze-x86_64-linux-gnu'))
            ->toThrow(RuntimeException::class, 'no checksum entry');
    } finally {
        @unlink($tar);
        @unlink($sums);
    }
});

it('strips Authorization on cross-host redirect but keeps it on same-host', function () {
    $headers = ['Authorization: Bearer t', 'Accept: x'];

    expect(BinaryDownloader::stripAuthOnCrossHost($headers, 'github.com', 's3.amazonaws.com'))
        ->toBe(['Accept: x']);
    expect(BinaryDownloader::stripAuthOnCrossHost($headers, 'github.com', 'github.com'))
        ->toBe($headers);
});

it('returns Skipped without touching the filesystem when opts->skip is true', function () {
    $result = (new BinaryDownloader)->install(new BinaryDownloadOptions(
        binDir: sys_get_temp_dir(),
        skip: true,
    ));

    expect($result->status)->toBe(BinaryDownloadStatus::Skipped)
        ->and($result->binPath)->toBeNull();
});

it('keeps the pinned version in lockstep with BinaryInstaller', function () {
    expect(BinaryDownloader::PINNED_VERSION)->toBe(BinaryInstaller::PINNED_VERSION);
});

/*
 * CB2 — IO channel characterization. The download pipeline moves into
 * BinaryDownloader but the per-message stdout-vs-stderr channel must be
 * preserved. The emitter contract is: info|comment -> stdout, warning|error
 * -> stderr. These two assertions pin the two channels at the service seam.
 */
it('emits the skip notice on the stdout channel (comment level)', function () {
    $events = [];
    (new BinaryDownloader)->install(
        new BinaryDownloadOptions(binDir: sys_get_temp_dir(), skip: true),
        function (string $level, string $message) use (&$events): void {
            $events[] = [$level, $message];
        },
    );

    expect($events)->toHaveCount(1);
    expect($events[0][0])->toBe('comment');
    expect($events[0][1])->toContain('skipping binary download');
});

it('refuses a non-HTTPS release base on the stderr channel (error level)', function () {
    $events = [];
    $result = (new BinaryDownloader)->install(
        new BinaryDownloadOptions(
            binDir: sys_get_temp_dir(),
            releaseBase: 'http://insecure.example/releases/download',
        ),
        function (string $level, string $message) use (&$events): void {
            $events[] = [$level, $message];
        },
    );

    expect($result->status)->toBe(BinaryDownloadStatus::Failed);
    $errors = array_values(array_filter($events, fn (array $e): bool => $e[0] === 'error'));
    expect($errors)->not->toBeEmpty();
    expect($errors[0][1])->toContain('non-HTTPS');
});

/*
 * CB2 — characterize the Composer adapter end-to-end: the message that was on
 * stderr before the refactor stays on stderr, and the stdout message stays on
 * stdout. BufferIO normally merges both streams, so we record the channel each
 * message was routed through.
 */
it('routes Composer-path messages to the same stdout/stderr channel as before the refactor', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-dl-io-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    $io = new class extends BufferIO
    {
        /** @var list<array{0: string, 1: string}> */
        public array $log = [];

        public function write($messages, $newline = true, $verbosity = self::NORMAL)
        {
            $this->log[] = ['out', is_array($messages) ? implode("\n", $messages) : (string) $messages];

            parent::write($messages, $newline, $verbosity);
        }

        public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
        {
            $this->log[] = ['err', is_array($messages) ? implode("\n", $messages) : (string) $messages];

            parent::writeError($messages, $newline, $verbosity);
        }
    };

    putenv('GAZE_SKIP_BINARY_DOWNLOAD=1');
    try {
        BinaryInstaller::postInstall(gl_makeEvent($io, $tmpDir));
    } finally {
        putenv('GAZE_SKIP_BINARY_DOWNLOAD');
        gl_recursiveRemove($tmpDir);
    }

    $stdout = array_values(array_filter($io->log, fn (array $e): bool => $e[0] === 'out'));
    $stderr = array_values(array_filter($io->log, fn (array $e): bool => $e[0] === 'err'));

    $skipOnStdout = array_filter($stdout, fn (array $e): bool => str_contains($e[1], 'skipping binary download'));
    $skipOnStderr = array_filter($stderr, fn (array $e): bool => str_contains($e[1], 'skipping binary download'));

    expect($skipOnStdout)->not->toBeEmpty();
    expect($skipOnStderr)->toBeEmpty();
});

it('keeps the GAZE_RELEASE_BASE override warning and non-HTTPS refusal on stderr', function () {
    $tmpDir = sys_get_temp_dir().'/gaze-dl-io-'.bin2hex(random_bytes(6));
    mkdir($tmpDir, 0755, true);

    $io = new class extends BufferIO
    {
        /** @var list<array{0: string, 1: string}> */
        public array $log = [];

        public function write($messages, $newline = true, $verbosity = self::NORMAL)
        {
            $this->log[] = ['out', is_array($messages) ? implode("\n", $messages) : (string) $messages];

            parent::write($messages, $newline, $verbosity);
        }

        public function writeError($messages, $newline = true, $verbosity = self::NORMAL)
        {
            $this->log[] = ['err', is_array($messages) ? implode("\n", $messages) : (string) $messages];

            parent::writeError($messages, $newline, $verbosity);
        }
    };

    putenv('APP_ENV=testing');
    putenv('GAZE_RELEASE_BASE=http://insecure.example/releases/download');
    try {
        BinaryInstaller::postInstall(gl_makeEvent($io, $tmpDir));
    } finally {
        putenv('APP_ENV');
        putenv('GAZE_RELEASE_BASE');
        gl_recursiveRemove($tmpDir);
    }

    $stderr = implode("\n", array_map(fn (array $e): string => $e[1], array_filter($io->log, fn (array $e): bool => $e[0] === 'err')));
    $stdout = implode("\n", array_map(fn (array $e): string => $e[1], array_filter($io->log, fn (array $e): bool => $e[0] === 'out')));

    expect($stderr)->toContain('non-canonical GAZE_RELEASE_BASE override');
    expect($stderr)->toContain('non-HTTPS');
    expect($stdout)->not->toContain('non-HTTPS');
});
