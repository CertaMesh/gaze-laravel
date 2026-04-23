<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Exceptions;

use Naoray\GazeLaravel\Queue\Contracts\RetryableWithAlert;

class GazeTimeoutException extends GazeInfraException implements RetryableWithAlert {}
