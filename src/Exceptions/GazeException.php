<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Variant;

class GazeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly string $stderrHash,
        public readonly ?Variant $variant = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }

    public function isCallerBug(): bool
    {
        return $this->variant?->exitBucket() === 1;
    }

    /**
     * @return array{exit_code:int,error_variant:?string,stderr_sha256:string}
     */
    public function toLogContext(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'error_variant' => $this->variant?->value,
            'stderr_sha256' => $this->stderrHash,
        ];
    }
}
