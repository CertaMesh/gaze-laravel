<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Closure;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin that fires {@see BinaryInstaller::install()} after this
 * package itself is installed or updated in the consumer's project.
 *
 * Without this, postInstall() would only run for the package's own dev
 * install (because Composer scripts in a dependency's composer.json are
 * not executed during a consumer's `composer require`). The plugin fixes
 * the dead-code path so end users actually get the binary on install.
 */
final class GazeInstallerPlugin implements EventSubscriberInterface, PluginInterface
{
    public const PACKAGE_NAME = 'naoray/gaze-laravel';

    /**
     * @var Closure(Composer, IOInterface): void
     */
    private Closure $installBinary;

    public function __construct(?Closure $installBinary = null)
    {
        $this->installBinary = $installBinary ?? BinaryInstaller::install(...);
    }

    public function activate(Composer $composer, IOInterface $io): void
    {
        // No setup needed — events do the work.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to tear down.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Composer removes vendor/bin/gaze with the package; no extra cleanup.
    }

    /**
     * @return array<string, string|array<int, array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageEvent',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageEvent',
        ];
    }

    public function onPackageEvent(PackageEvent $event): void
    {
        $operation = $event->getOperation();

        $package = match (true) {
            $operation instanceof InstallOperation => $operation->getPackage(),
            $operation instanceof UpdateOperation => $operation->getTargetPackage(),
            default => null,
        };

        if ($package === null || $package->getName() !== self::PACKAGE_NAME) {
            return;
        }

        ($this->installBinary)($event->getComposer(), $event->getIO());
    }
}
