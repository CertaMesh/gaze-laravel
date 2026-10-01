<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Variant;

/**
 * Pinned-artifact contract violation: a safety-net backend was requested but
 * its required artifact (e.g. the Nym bundle fetched with
 * `gaze setup --safety-net nym` and located by the policy's
 * `[safety_net.nym] model_dir` or `GAZE_NYM_MODEL_DIR`) is missing on disk.
 * `path()` carries upstream's placeholder (`<missing:SHA256SUMS> (install via
 * …)`), not the configured directory.
 *
 * Maps to upstream `CliError::SafetyNetArtifactMissing { backend, path }`
 * (exit 2). Axis-1 fail-closed: the binary never silently disables a backend
 * when its pinned artifact is absent. Retry-classified as
 * {@see NonRetryable} via the
 * `GazeOpsConfigException` ancestor — retrying without fixing the artifact
 * path cannot succeed.
 */
final class GazeSafetyNetArtifactMissingException extends GazePolicyConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly string $backend,
        private readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $exitCode,
            $stderrHash,
            $previous,
            Variant::SafetyNetArtifactMissing,
        );
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function path(): string
    {
        return $this->path;
    }
}
