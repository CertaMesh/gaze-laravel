<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Single source of truth for the pinned Kiji DistilBERT artifact contract.
 *
 * Both `gaze:doctor` (post-write probe) and `gaze:install:safety-net`
 * (pre-write gate) validate against this list, so the writer never produces a
 * `.env` that doctor would then reject. The artifacts themselves are fetched by
 * upstream `scripts/fetch-kiji-safetynet-model.sh` (dir 0o700, files 0o600) —
 * never downloaded here.
 */
final class KijiArtifacts
{
    /** @var list<string> */
    public const REQUIRED = ['SHA256SUMS', 'labels.json', 'model.onnx', 'tokenizer.json'];

    /**
     * Required artifacts absent from `$dir`. An empty/blank dir reports every
     * artifact as missing.
     *
     * @return list<string>
     */
    public static function missing(?string $dir): array
    {
        if (! is_string($dir) || $dir === '') {
            return self::REQUIRED;
        }

        $missing = [];
        foreach (self::REQUIRED as $name) {
            if (! is_file($dir.DIRECTORY_SEPARATOR.$name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }
}
