<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;

function gl_makeExecutable(string $dir, string $name): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/bin/sh\necho stub\n");
    chmod($path, 0755);

    return $path;
}

function gl_recursiveRemove(string $dir): void
{
    if (! is_dir($dir)) {
        @unlink($dir);

        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? gl_recursiveRemove($path) : @unlink($path);
    }

    @rmdir($dir);
}

function gl_makeEvent(BufferIO $io, string $binDir): Event
{
    $config = new Config(false);
    $config->merge(['config' => ['bin-dir' => $binDir]]);

    $composer = new Composer;
    $composer->setConfig($config);

    return new Event('post-install-cmd', $composer, $io);
}

function gl_makeProcessFixture(string $dir, string $name, string $phpBody): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/usr/bin/env php\n<?php\n{$phpBody}\n");
    chmod($path, 0755);

    return $path;
}

/** @param array<string, string> $files */
function gl_buildFixtureTarGz(string $tmpDir, string $stagingDir, array $files): string
{
    if (is_dir($stagingDir)) {
        gl_recursiveRemove($stagingDir);
    }

    mkdir($stagingDir, 0755, true);
    foreach ($files as $name => $contents) {
        file_put_contents($stagingDir.'/'.$name, $contents);
    }

    $tarPath = $tmpDir.'/pkg.tar';
    if (file_exists($tarPath)) {
        unlink($tarPath);
    }
    if (file_exists($tarPath.'.gz')) {
        unlink($tarPath.'.gz');
    }

    $tar = new PharData($tarPath);
    foreach (array_keys($files) as $name) {
        $tar->addFile($stagingDir.'/'.$name, $name);
    }
    unset($tar);

    $gzPath = $tarPath.'.gz';
    $tarBytes = file_get_contents($tarPath);
    if ($tarBytes === false) {
        throw new RuntimeException('could not read fixture tar');
    }

    Phar::unlinkArchive($tarPath);
    file_put_contents($gzPath, gzencode($tarBytes, 9));

    return $gzPath;
}
