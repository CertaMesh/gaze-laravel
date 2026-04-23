<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

final class BinaryResolver
{
    public function __construct(
        private readonly ?string $explicitPath,
        private readonly string $vendorBinPath,
    ) {}

    public function resolve(): string
    {
        if ($this->explicitPath !== null && $this->explicitPath !== '') {
            return $this->explicitPath;
        }

        if (is_executable($this->vendorBinPath)) {
            return $this->vendorBinPath;
        }

        $which = @shell_exec('command -v ghostwriter 2>/dev/null');
        if (is_string($which) && ($trimmed = trim($which)) !== '') {
            return $trimmed;
        }

        throw new GazeBinaryMissingException(
            'ghostwriter binary not found. Set GAZE_BINARY, install the binary, '
            .'or add the naoray/gaze-laravel post-install-cmd to composer.json.'
        );
    }
}
