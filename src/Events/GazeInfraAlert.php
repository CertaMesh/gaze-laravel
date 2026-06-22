<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Events;

final readonly class GazeInfraAlert
{
    public function __construct(public \Throwable $exception) {}
}
