<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Queue;

enum RetryAction
{
    case Fail;
    case ReleaseWithBackoff;
    case ReleaseWithAlert;
    case Throw;
}
