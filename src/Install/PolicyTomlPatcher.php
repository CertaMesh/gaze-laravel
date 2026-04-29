<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

use Yosymfony\Toml\Exception\ParseException;
use Yosymfony\Toml\Toml;

final class PolicyTomlPatcher
{
    public function hasNerBlock(string $body): bool
    {
        $parsed = $this->parse($body);

        return array_key_exists('ner', $parsed) && is_array($parsed['ner']);
    }

    public function readModelDir(string $body): ?string
    {
        $parsed = $this->parse($body);

        if (! array_key_exists('ner', $parsed) || ! is_array($parsed['ner'])) {
            return null;
        }

        $modelDir = $parsed['ner']['model_dir'] ?? null;

        return is_string($modelDir) && $modelDir !== '' ? $modelDir : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $body): array
    {
        try {
            /** @var array<string, mixed> $parsed */
            $parsed = Toml::parse($body);

            return $parsed;
        } catch (ParseException $e) {
            throw new NerManifestInvalidException('invalid policy TOML: '.$e->getMessage(), previous: $e);
        }
    }
}
