<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ArrayRepository;
use Naoray\GazeLaravel\Install\GazeInstallerPlugin;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
    $this->originalCwd = getcwd();
    $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-plugin-'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    if ($this->originalCwd !== false) {
        chdir($this->originalCwd);
    }

    glp_recursiveRemove($this->tmpDir);
});

it('subscribes to POST_PACKAGE_INSTALL and POST_PACKAGE_UPDATE', function () {
    $events = GazeInstallerPlugin::getSubscribedEvents();

    expect($events)
        ->toHaveKey(PackageEvents::POST_PACKAGE_INSTALL)
        ->and($events)->toHaveKey(PackageEvents::POST_PACKAGE_UPDATE);
});

it('maps both subscribed events to the onPackageEvent handler', function () {
    $events = GazeInstallerPlugin::getSubscribedEvents();
    $plugin = new GazeInstallerPlugin;

    foreach ([PackageEvents::POST_PACKAGE_INSTALL, PackageEvents::POST_PACKAGE_UPDATE] as $key) {
        $handler = $events[$key];
        if (! is_string($handler)) {
            throw new RuntimeException('expected plugin event handler name');
        }

        expect($handler)->toBeString();
        expect(method_exists($plugin, $handler))->toBeTrue();
        expect((new ReflectionMethod($plugin, $handler))->isPublic())->toBeTrue();
    }
});

it('implements the Composer plugin contract', function () {
    $plugin = new GazeInstallerPlugin;

    expect($plugin)->toBeInstanceOf(PluginInterface::class)
        ->and($plugin)->toBeInstanceOf(EventSubscriberInterface::class);
});

it('does not install for a foreign package install operation', function () {
    $installCalls = 0;
    $plugin = gazeInstallerPluginSpy($installCalls);

    $plugin->onPackageEvent(packageEvent(new InstallOperation(composerPackage('vendor/foreign-package'))));

    expect($installCalls)->toBe(0);
});

it('does not clean up binaries for a foreign package install operation', function () {
    $installCalls = 0;
    $plugin = gazeInstallerPluginSpy($installCalls);
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);
    file_put_contents($binDir.'/gaze', 'foreign package should not remove this');

    $plugin->onPackageEvent(packageEvent(new InstallOperation(composerPackage('vendor/foreign-package'))));

    expect($installCalls)->toBe(0)
        ->and($binDir.'/gaze')->toBeFile();
});

it('installs for a self-named package update operation', function () {
    $installCalls = 0;
    $plugin = gazeInstallerPluginSpy($installCalls);

    $plugin->onPackageEvent(packageEvent(new UpdateOperation(
        composerPackage(GazeInstallerPlugin::PACKAGE_NAME),
        composerPackage(GazeInstallerPlugin::PACKAGE_NAME),
    )));

    expect($installCalls)->toBe(1);
});

it('does not install for a self-named package uninstall operation', function () {
    $installCalls = 0;
    $plugin = gazeInstallerPluginSpy($installCalls);

    $plugin->onPackageEvent(packageEvent(new UninstallOperation(composerPackage(GazeInstallerPlugin::PACKAGE_NAME))));

    expect($installCalls)->toBe(0);
});

it('removes the gaze binary from Composer bin-dir on uninstall', function () {
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);
    file_put_contents($binDir.'/gaze', "#!/bin/sh\necho gaze\n");
    chmod($binDir.'/gaze', 0755);

    $plugin = new GazeInstallerPlugin;
    $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

    $plugin->uninstall(composerWithBinDir($binDir), $io);

    expect($binDir.'/gaze')->not->toBeFile()
        ->and($io->getOutput())->toContain('gaze-laravel: removed '.$binDir.'/gaze');
});

it('removes the gaze binary from a relative Composer bin-dir anchored to vendor-dir', function () {
    $projectRoot = $this->tmpDir.'/project';
    $binDir = $projectRoot.'/vendor/bin';
    mkdir($binDir, 0755, true);
    file_put_contents($binDir.'/gaze', "#!/bin/sh\necho gaze\n");
    chmod($binDir.'/gaze', 0755);

    $workingDir = $this->tmpDir.'/different-cwd';
    $wrongBinDir = $workingDir.'/vendor/bin';
    mkdir($wrongBinDir, 0755, true);
    file_put_contents($wrongBinDir.'/gaze', 'wrong project binary');

    chdir($workingDir);

    $plugin = new GazeInstallerPlugin;
    $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

    $plugin->uninstall(composerWithBinDir('vendor/bin', $projectRoot.'/vendor'), $io);

    expect($binDir.'/gaze')->not->toBeFile()
        ->and($wrongBinDir.'/gaze')->toBeFile()
        ->and($io->getOutput())->toContain('gaze-laravel: removed '.$binDir.'/gaze');
});

it('removes a Windows gaze bat shim from Composer bin-dir on uninstall', function () {
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);
    file_put_contents($binDir.'/gaze.bat', '@echo off');
    chmod($binDir.'/gaze.bat', 0644);

    $plugin = new GazeInstallerPlugin;

    $plugin->uninstall(composerWithBinDir($binDir), new BufferIO);

    expect($binDir.'/gaze.bat')->not->toBeFile();
});

it('is idempotent when Composer bin-dir has no gaze binary', function () {
    $binDir = $this->tmpDir.'/bin';
    mkdir($binDir, 0755, true);

    $plugin = new GazeInstallerPlugin;

    $plugin->uninstall(composerWithBinDir($binDir), new BufferIO);
    $plugin->uninstall(composerWithBinDir($binDir), new BufferIO);

    expect($binDir.'/gaze')->not->toBeFile()
        ->and($binDir.'/gaze.bat')->not->toBeFile();
});

it('pins the Composer package name used by the installer plugin', function () {
    expect(GazeInstallerPlugin::PACKAGE_NAME)->toBe('naoray/gaze-laravel');
});

function glp_recursiveRemove(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        glp_recursiveRemove($path.'/'.$entry);
    }

    @rmdir($path);
}

function gazeInstallerPluginSpy(int &$installCalls): GazeInstallerPlugin
{
    return new GazeInstallerPlugin(function (Composer $composer, IOInterface $io) use (&$installCalls): void {
        $installCalls++;
    });
}

function composerWithBinDir(string $binDir, ?string $vendorDir = null): Composer
{
    $config = new Config(false);
    $settings = ['bin-dir' => $binDir];

    if ($vendorDir !== null) {
        $settings['vendor-dir'] = $vendorDir;
    }

    $config->merge(['config' => $settings]);

    $composer = new Composer;
    $composer->setConfig($config);

    return $composer;
}

function packageEvent(OperationInterface $operation): PackageEvent
{
    return new PackageEvent(
        PackageEvents::POST_PACKAGE_INSTALL,
        new Composer,
        new BufferIO,
        false,
        new ArrayRepository,
        [$operation],
        $operation,
    );
}

function composerPackage(string $name): Package
{
    return new Package($name, '1.0.0.0', '1.0.0');
}
