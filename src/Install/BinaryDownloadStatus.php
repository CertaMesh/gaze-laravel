<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Outcome of a {@see BinaryDownloader::install()} attempt.
 *
 * The Composer plugin path treats every case as non-fatal (best effort); the
 * artisan path maps `Installed`/`AlreadySatisfied`/`Skipped` to success and
 * `UnsupportedPlatform`/`Failed` to a real exit code.
 */
enum BinaryDownloadStatus: string
{
    case Installed = 'installed';
    case AlreadySatisfied = 'already-satisfied';
    case Skipped = 'skipped';
    case UnsupportedPlatform = 'unsupported-platform';
    case Failed = 'failed';
}
