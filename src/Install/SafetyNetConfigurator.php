<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Idempotent `.env` upsert for the gaze safety-net backend wiring.
 *
 * The config keys are `env()`-backed, so `.env` is the canonical write target.
 * Writes are idempotent (re-running with the same backend is a no-op) and the
 * original `.env` is preserved write-once in `.env.backup` (0600, and gitignored
 * by Laravel's default `.gitignore`) so the change is fully reversible — a
 * `--force` re-run never refreshes that pristine backup.
 *
 * A "sensitive value" seam ({@see self::isSensitiveKey()}) redacts secret-shaped
 * keys in {@see self::preview()} so a future secret-valued key can never leak to
 * CI logs via `--print`.
 */
final class SafetyNetConfigurator
{
    public function __construct(private readonly string $envPath) {}

    /**
     * Build the `.env` pairs for a safety-net backend.
     *
     * `opf` (openai-filter) is a LOCAL subprocess — the command + checkpoint are
     * filesystem paths, symmetric with kiji's model dir — NOT an OpenAI API key.
     *
     * @return array<string, string>
     */
    public static function pairsFor(
        string $backend,
        ?string $kijiModelDir,
        ?string $opfCommand = null,
        ?string $opfCheckpoint = null,
    ): array {
        return match ($backend) {
            'opf' => self::nonEmpty([
                'GAZE_SAFETY_NET' => 'true',
                'GAZE_SAFETY_NET_BACKEND' => 'openai-filter',
                'GAZE_OPENAI_FILTER_COMMAND' => $opfCommand,
                'GAZE_OPENAI_FILTER_CHECKPOINT' => $opfCheckpoint,
            ]),
            'kiji' => self::nonEmpty([
                'GAZE_SAFETY_NET' => 'true',
                'GAZE_SAFETY_NET_BACKEND' => 'kiji-distilbert',
                'GAZE_KIJI_BACKEND' => 'ort',
                'GAZE_KIJI_DISTILBERT_PRECISION' => 'int8',
                'GAZE_KIJI_DISTILBERT_MODEL_DIR' => $kijiModelDir,
            ]),
            default => throw new \InvalidArgumentException("unknown safety-net backend: {$backend}"),
        };
    }

    /**
     * Idempotent upsert. Returns `unchanged` (no write, no backup) when every
     * key already holds the target value; otherwise writes `.env` atomically
     * after taking a write-once 0600 backup.
     *
     * @param  array<string, string>  $pairs
     */
    public function apply(array $pairs, bool $force): SafetyNetConfiguratorResult
    {
        $exists = is_file($this->envPath);
        $original = $exists ? (string) file_get_contents($this->envPath) : '';
        $updated = $this->upsert($original, $pairs);

        if ($exists && $updated === $original) {
            return new SafetyNetConfiguratorResult('unchanged', $pairs, $this->envPath, null);
        }

        $backupPath = $this->envPath.'.backup';

        // CB6: write-once backup — never refresh the pristine original, even on
        // --force. ($force is accepted for API symmetry but must not clobber it.)
        if ($exists && ! is_file($backupPath)) {
            if (! copy($this->envPath, $backupPath)) {
                throw new \RuntimeException("could not write .env backup: {$backupPath}");
            }
        }
        if (is_file($backupPath)) {
            @chmod($backupPath, 0600);
        }

        if (file_put_contents($this->envPath, $updated, LOCK_EX) === false) {
            throw new \RuntimeException("could not write .env: {$this->envPath}");
        }

        return new SafetyNetConfiguratorResult('written', $pairs, $this->envPath, is_file($backupPath) ? $backupPath : null);
    }

    /**
     * Render the `KEY=value` lines without touching `.env`. Secret-shaped keys
     * are redacted so `--print` output is safe to paste into CI logs.
     *
     * @param  array<string, string>  $pairs
     */
    public function preview(array $pairs): string
    {
        $lines = [];
        foreach ($pairs as $key => $value) {
            $lines[] = $key.'='.(self::isSensitiveKey($key) ? '***redacted***' : $value);
        }

        return implode("\n", $lines);
    }

    /** Heuristic seam: redact future secret-valued keys (none today are secrets). */
    public static function isSensitiveKey(string $key): bool
    {
        return preg_match('/(?:_KEY|_TOKEN|_SECRET|_PASSWORD|_PASSWD)$/i', $key) === 1;
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function upsert(string $content, array $pairs): string
    {
        foreach ($pairs as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $content) === 1) {
                $replaced = preg_replace_callback($pattern, static fn (): string => $line, $content, 1);
                $content = is_string($replaced) ? $replaced : $content;

                continue;
            }

            if ($content !== '' && ! str_ends_with($content, "\n")) {
                $content .= "\n";
            }
            $content .= $line."\n";
        }

        return $content;
    }

    /**
     * @param  array<string, ?string>  $pairs
     * @return array<string, string>
     */
    private static function nonEmpty(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $key => $value) {
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
