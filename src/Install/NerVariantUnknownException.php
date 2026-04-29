<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

final class NerVariantUnknownException extends NerInstallException
{
    /**
     * @param  list<string>  $known
     */
    public function __construct(string $variant, public readonly array $known)
    {
        parent::__construct(sprintf(
            'unknown NER variant "%s"; known variants: %s',
            $variant,
            implode(', ', $known),
        ));
    }

    public function exitCode(): int
    {
        return 2;
    }
}
