<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Install\BinaryInstaller;
use Symfony\Component\Process\Process;

const HELP_SNAPSHOTS = [
    'version.txt' => ['version.txt', ['--version']],
    'help.txt' => ['help.txt', ['--help']],
    'help-clean.txt' => ['help-clean.txt', ['clean', '--help']],
    'help-restore.txt' => ['help-restore.txt', ['restore', '--help']],
    'help-audit.txt' => ['help-audit.txt', ['audit', '--help']],
    'help-audit-purge.txt' => ['help-audit-purge.txt', ['audit', 'purge', '--help']],
    'help-audit-query.txt' => ['help-audit-query.txt', ['audit', 'query', '--help']],
    'help-audit-export.txt' => ['help-audit-export.txt', ['audit', 'export', '--help']],
];

it('pins the installed gaze help surface for the pinned upstream version', function (string $snapshot, array $arguments) {
    $binary = resolveGazeContractBinary();

    if ($binary === null) {
        $this->markTestSkipped('gaze binary unavailable; set GAZE_BINARY or install vendor/bin/gaze to run help snapshot contracts.');
    }

    expect($binary)->not->toBeNull();

    $process = new Process([$binary, ...$arguments]);
    $process->mustRun();

    $actual = normalizeHelpOutput($process->getOutput(), basename($binary));
    $expected = readHelpSnapshot($snapshot);

    expect($actual)->toBe($expected);
})->with(HELP_SNAPSHOTS);

function resolveGazeContractBinary(): ?string
{
    $fromEnv = getenv('GAZE_BINARY');
    if (is_string($fromEnv) && $fromEnv !== '' && is_executable($fromEnv)) {
        return $fromEnv;
    }

    $vendorBin = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'gaze';
    if (is_executable($vendorBin)) {
        return $vendorBin;
    }

    $path = trim((string) shell_exec('command -v gaze 2>/dev/null'));
    if ($path !== '' && is_executable($path)) {
        return $path;
    }

    return null;
}

function normalizeHelpOutput(string $output, string $binaryName): string
{
    $normalized = str_replace("\r\n", "\n", trim($output));
    $normalized = preg_replace(
        '/^Usage: '.preg_quote($binaryName, '/').'/m',
        'Usage: gaze',
        $normalized,
    );
    $normalized = preg_replace(
        '/^gaze '.preg_quote(BinaryInstaller::PINNED_VERSION, '/').'$/m',
        'gaze '.BinaryInstaller::PINNED_VERSION,
        $normalized,
    );

    if (! is_string($normalized)) {
        throw new RuntimeException('failed to normalize help output');
    }

    return $normalized;
}

function readHelpSnapshot(string $snapshot): string
{
    $path = __DIR__.DIRECTORY_SEPARATOR.'__snapshots__'.DIRECTORY_SEPARATOR.$snapshot;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("could not read help snapshot {$snapshot}");
    }

    return str_replace("\r\n", "\n", trim($contents));
}
