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

function gl_nerChecksumFixture(): string
{
    return implode("\n", [
        '1213fdd405d295768b0d41d8214062f2f278f0e3acff6af67d8fd47360d2be0f  model.onnx',
        'bf1b59b7b11c95f194f51708d918eea378e09d05f84c0e1656dc5180e8117088  tokenizer.json',
        '470cff6e0353b08e2a6e9b4f61729ecdc47ccb3ced335fa5520e9ce334572d59  tokenizer_config.json',
        '8e5caefadaf9923a9e7d3de42ca97780c68fc4d83519d333f141b299e40af638  config.json',
        'b6d346be366a7d1d48332dbc9fdf3bf8960b5d879522b7799ddba59e76237ee3  special_tokens_map.json',
        'fe0fda7c425b48c516fc8f160d594c8022a0808447475c1a7c6d6479763f310c  vocab.txt',
        '8498e2bafc017a793571c3c2f7092390a93a757f5ca45004f21db2560a8c6fdb  labels.json',
    ]);
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
