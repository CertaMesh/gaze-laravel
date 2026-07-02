<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Audit;

/**
 * Result of a `gaze audit export` run.
 *
 * Upstream (pinned 0.11.x) does not report a row count on export; when the
 * export went to stdout (`$path === null`) the JSONL payload is captured in
 * `$rawOutput` and `rowCount()` / `rows()` derive from it. When `--output`
 * was used, upstream writes the file itself and prints nothing — the
 * adapter deliberately does not read the file back, so `rowCount()` returns
 * null and `rows()` returns an empty list.
 */
final readonly class AuditExportResult
{
    public function __construct(
        public string $format,
        public ?string $path,
        public string $rawOutput,
    ) {}

    /**
     * Number of exported rows, counted from the captured stdout lines.
     * Null when the export was written to a file via `--output`.
     */
    public function rowCount(): ?int
    {
        if ($this->path !== null) {
            return null;
        }

        return count($this->lines());
    }

    /**
     * Decoded JSONL rows keyed by upstream column name. Only populated for
     * stdout exports; rows that are not valid JSON objects are skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->lines() as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (trim($this->rawOutput) === '') {
            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($this->rawOutput)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
