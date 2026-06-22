<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Outcome of a {@see SafetyNetConfigurator} operation.
 *
 * `status`:
 *   - `written`   — `.env` was mutated (a write-once `.env.backup` exists)
 *   - `unchanged` — every key already had the target value; nothing written
 *   - `previewed` — `--print` path; `.env` untouched
 */
final class SafetyNetConfiguratorResult
{
    /**
     * @param  array<string, string>  $pairs
     */
    public function __construct(
        public readonly string $status,
        public readonly array $pairs,
        public readonly string $envPath,
        public readonly ?string $backupPath,
    ) {}
}
