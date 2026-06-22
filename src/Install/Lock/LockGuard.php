<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install\Lock;

use CertaMesh\Gaze\Install\NerLockHeldException;
use CertaMesh\Gaze\Install\NerTransportException;

final class LockGuard
{
    /** @param resource $handle */
    private function __construct(
        private $handle,
        private bool $released = false,
    ) {}

    public static function acquire(string $path): self
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new NerTransportException("could not create lock directory: {$dir}");
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new NerTransportException("could not open lockfile: {$path}");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new NerLockHeldException($path);
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf("%d %s\n", getmypid(), date('c')));
        fflush($handle);

        return new self($handle);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
