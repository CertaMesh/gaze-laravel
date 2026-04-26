<?php

declare(strict_types=1);

use Composer\Installer\PackageEvents;
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

    expect($plugin)->toBeInstanceOf(\Composer\Plugin\PluginInterface::class)
        ->and($plugin)->toBeInstanceOf(\Composer\EventDispatcher\EventSubscriberInterface::class);
});
