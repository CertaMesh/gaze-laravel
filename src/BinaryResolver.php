<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Symfony\Component\Process\ExecutableFinder;

final class BinaryResolver
{
    private ?string $resolved = null;

    public function __construct(
        private readonly ?string $explicitPath,
        private readonly string $vendorBinPath,
    ) {}

    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->explicitPath !== null && $this->explicitPath !== '') {
            return $this->resolved = $this->explicitPath;
        }

        if (is_executable($this->vendorBinPath)) {
            return $this->resolved = $this->vendorBinPath;
        }

        $path = (new ExecutableFinder)->find('gaze');
        if ($path !== null) {
            return $this->resolved = $path;
        }

        throw new GazeBinaryMissingException(
            'gaze binary not found. Set GAZE_BINARY, install the binary, '
            .'or add the empiretwo/gaze-laravel post-install-cmd to composer.json.'
        );
    }

    public function resolveOrNull(): ?string
    {
        try {
            return $this->resolve();
        } catch (GazeBinaryMissingException) {
            return null;
        }
    }
}
