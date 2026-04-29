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

    public function buildReplaced(string $body, string $modelDir, ?string $locale, bool $force): string
    {
        if (! $this->hasNerBlock($body)) {
            return $this->buildAppended($body, $modelDir, $locale);
        }

        $currentModelDir = $this->readModelDir($body);
        $patched = $this->replaceNerModelDir($body, $modelDir);

        if ($locale !== null && $locale !== '') {
            $patched = $this->replaceOrAppendNerValue($patched, 'locale', $this->tomlString($locale));
        }

        if ($currentModelDir === $modelDir && $patched === $body) {
            return $body;
        }

        if ($currentModelDir !== null && $currentModelDir !== $modelDir && ! $force) {
            throw new NerPolicyConflictException($this->diff($body, $patched));
        }

        $this->parse($patched);

        return $patched;
    }

    public function apply(string $policyPath, string $modelDir, ?string $locale, bool $force): string
    {
        $body = file_get_contents($policyPath);
        if ($body === false) {
            throw new NerManifestInvalidException("could not read policy file: {$policyPath}");
        }

        $patched = $this->hasNerBlock($body)
            ? $this->buildReplaced($body, $modelDir, $locale, $force)
            : $this->buildAppended($body, $modelDir, $locale);

        if ($patched === $body) {
            return $patched;
        }

        $backupPath = $policyPath.'.bak';
        if (! is_file($backupPath) && ! copy($policyPath, $backupPath)) {
            throw new NerManifestInvalidException("could not write policy backup: {$backupPath}");
        }

        if (file_put_contents($policyPath, $patched, LOCK_EX) === false) {
            throw new NerManifestInvalidException("could not write policy file: {$policyPath}");
        }

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

            return is_array($parsed) ? $parsed : [];
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

    private function replaceNerModelDir(string $body, string $modelDir): string
    {
        return $this->replaceOrAppendNerValue($body, 'model_dir', $this->tomlString($modelDir));
    }

    private function replaceOrAppendNerValue(string $body, string $key, string $encodedValue): string
    {
        [$start, $end] = $this->nerSectionBounds($body);
        $section = substr($body, $start, $end - $start);
        $replacement = $key.' = '.$encodedValue;
        $count = 0;
        $newSection = preg_replace(
            '/^(\s*)'.preg_quote($key, '/').'\s*=.*$/m',
            '${1}'.$replacement,
            $section,
            1,
            $count,
        );

        if (! is_string($newSection)) {
            throw new NerManifestInvalidException("failed to patch [ner].{$key}");
        }

        if ($count === 0) {
            $newSection = rtrim($section, "\n")."\n{$replacement}\n";
        }

        return substr($body, 0, $start).$newSection.substr($body, $end);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function nerSectionBounds(string $body): array
    {
        if (preg_match('/^\s*\[ner\]\s*$/m', $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new NerManifestInvalidException('policy does not contain [ner] block');
        }

        $start = $match[0][1];
        $afterHeader = $start + strlen($match[0][0]);

        if (preg_match('/^\s*\[[^\]]+\]\s*$/m', $body, $next, PREG_OFFSET_CAPTURE, $afterHeader) === 1) {
            return [$start, $next[0][1]];
        }

        return [$start, strlen($body)];
    }

    private function diff(string $before, string $after): string
    {
        $beforeLines = explode("\n", $before);
        $afterLines = explode("\n", $after);
        $lines = ['--- current', '+++ proposed'];
        $max = max(count($beforeLines), count($afterLines));

        for ($i = 0; $i < $max; $i++) {
            $old = $beforeLines[$i] ?? null;
            $new = $afterLines[$i] ?? null;

            if ($old === $new) {
                continue;
            }

            if ($old !== null) {
                $lines[] = '-'.$old;
            }

            if ($new !== null) {
                $lines[] = '+'.$new;
            }
        }

        return implode("\n", $lines);
    }
}
