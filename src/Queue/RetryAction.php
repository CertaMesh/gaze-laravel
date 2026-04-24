<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Queue;

enum RetryAction
{
    case Fail;
    case ReleaseWithBackoff;
    case ReleaseWithAlert;
    case Throw;
}
