<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Events;

final readonly class GazeInfraAlert
{
    public function __construct(public \Throwable $exception) {}
}
