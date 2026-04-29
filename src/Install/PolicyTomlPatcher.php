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

    public function buildAppended(string $body, string $modelDir, ?string $locale): string
    {
        $this->parse($body);

        $block = "\n\n[ner]\n";
        $block .= 'model_dir = '.$this->tomlString($modelDir)."\n";

        if ($locale !== null && $locale !== '') {
            $block .= 'locale = '.$this->tomlString($locale)."\n";
        } else {
            $block .= "# locale = \"de\"          # optional BCP47 (single string)\n";
        }

        $block .= "# threshold = 0.4         # optional 0.0..=1.0\n";

        $patched = rtrim($body, "\n").$block;
        $this->parse($patched);

        return $patched;
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

    private function tomlString(string $value): string
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new NerManifestInvalidException('policy value contains unsupported control characters');
        }

        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
        ]).'"';
    }
}
