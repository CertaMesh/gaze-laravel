<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;

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

        $out = [];
        $rc = 0;
        @exec('command -v gaze 2>/dev/null', $out, $rc);
        $trimmed = trim(implode("\n", $out));
        if ($rc === 0 && $trimmed !== '') {
            return $this->resolved = $trimmed;
        }

        throw new GazeBinaryMissingException(
            'gaze binary not found. Set GAZE_BINARY, install the binary, '
            .'or add the naoray/gaze-laravel post-install-cmd to composer.json.'
        );
    }
}
