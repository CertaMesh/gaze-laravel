<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

class GazePolicyConfigException extends GazeOpsConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        string $stderrHash,
        ?\Throwable $previous = null,
        Variant $variant = Variant::PolicyConfig,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }
}
