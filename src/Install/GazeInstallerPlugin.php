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

    /**
     * Remove binaries written by {@see BinaryInstaller::install()}.
     *
     * Composer does not track files this plugin places in the consumer's
     * bin-dir at runtime, so package removal must clean them up explicitly.
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $binDir = (string) $composer->getConfig()->get('bin-dir');
        if (! str_starts_with($binDir, '/') && ! preg_match('/^[A-Z]:[\\\\\\/]/i', $binDir)) {
            $vendorDir = (string) $composer->getConfig()->get('vendor-dir');
            $projectRoot = $vendorDir !== ''
                ? dirname($vendorDir)
                : (getcwd() ?: dirname(__DIR__, 2));

            $binDir = rtrim($projectRoot, '/').'/'.ltrim($binDir, './');
        }

        foreach (['gaze', 'gaze.bat'] as $name) {
            $path = $binDir.'/'.$name;

            if (is_file($path) && is_writable($path) && @unlink($path)) {
                $io->write("<info>gaze-laravel: removed {$path}</info>", true, IOInterface::VERBOSE);
            }
        }
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
