<?php

declare(strict_types=1);

use Composer\Composer;
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

it('pins the Composer package name used by the installer plugin', function () {
    expect(GazeInstallerPlugin::PACKAGE_NAME)->toBe('naoray/gaze-laravel');
});

function gazeInstallerPluginSpy(int &$installCalls): GazeInstallerPlugin
{
    return new GazeInstallerPlugin(function (Composer $composer, IOInterface $io) use (&$installCalls): void {
        $installCalls++;
    });
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
