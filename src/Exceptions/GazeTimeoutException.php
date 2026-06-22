<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\RetryableWithAlert;

class GazeTimeoutException extends GazeInfraException implements RetryableWithAlert {}
