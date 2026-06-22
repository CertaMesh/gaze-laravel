<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Result of a {@see BinaryDownloader::install()} attempt. `binPath` is the
 * resolved install path when known (Installed / AlreadySatisfied), null
 * otherwise.
 */
final class BinaryDownloadResult
{
    public function __construct(
        public readonly BinaryDownloadStatus $status,
        public readonly ?string $binPath,
        public readonly string $version,
        public readonly string $message,
    ) {}
}
