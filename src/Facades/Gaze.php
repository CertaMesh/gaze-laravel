<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Facades;

use Illuminate\Support\Facades\Facade;

final class Gaze extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Naoray\GazeLaravel\Gaze::class;
    }
}
