<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerPolicyConflictException extends NerInstallException
{
    public function __construct(public readonly string $diff)
    {
        parent::__construct("[ner] block already exists with different model_dir; rerun with --force\n\n{$diff}");
    }

    public function exitCode(): int
    {
        return 1;
    }
}
