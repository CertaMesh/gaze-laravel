<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Gaze;
use Illuminate\Console\Command;
use Illuminate\Process\Factory as ProcessFactory;

final class BenchCommand extends Command
{
    protected $signature = 'gaze:bench
        {--requests=10 : Number of sequential Gaze::clean calls to run}
        {--text= : Override sample text}
        {--json : Emit a single JSON object}
        {--samples=head : Sample output for large runs: full, head, or none}';

    protected $description = 'Measure cold Gaze::clean latency under the current one-shot CLI contract.';

    private const DEFAULT_TEXT = 'Hallo, ich bin Anna Schmidt (anna@example.de). Bitte storniere Bestellung ORD-DEMO-77.';

    /**
     * Bench data treats only GazeException failures as command failures. Other
     * throwables are coding/runtime bugs and intentionally propagate.
     */
    public function handle(Gaze $gaze, BinaryResolver $resolver, ProcessFactory $process): int
    {
        $requests = (int) $this->option('requests');
        if ($requests < 1) {
            $this->error('--requests must be >= 1');

            return self::FAILURE;
        }

        $samplesOption = $this->option('samples');
        $samplesMode = is_string($samplesOption) ? $samplesOption : 'head';
        if (! in_array($samplesMode, ['full', 'head', 'none'], true)) {
            $this->error('--samples must be one of: full, head, none');

            return self::FAILURE;
        }

        $textOption = $this->option('text');
        $text = is_string($textOption) && $textOption !== '' ? $textOption : self::DEFAULT_TEXT;
        $latenciesMs = [];
        $totalStart = hrtime(true);

        for ($i = 0; $i < $requests; $i++) {
            $callStart = hrtime(true);

            try {
                $gaze->clean($text);
            } catch (GazeException $e) {
                $this->error('gaze:bench aborted at iteration '.$i.': '.$e->getMessage());

                return self::FAILURE;
            }

            $latenciesMs[] = (hrtime(true) - $callStart) / 1_000_000.0;
        }

        $totalWallMs = (hrtime(true) - $totalStart) / 1_000_000.0;
        $sortedLatenciesMs = $latenciesMs;
        sort($sortedLatenciesMs);

        $payload = [
            'bench_schema_version' => 1,
            'mode' => 'cold',
            'requests' => $requests,
            'first_ms' => self::roundMs($latenciesMs[0]),
            'total_wall_ms' => self::roundMs($totalWallMs),
            'p50_ms' => self::roundMs(self::percentile($sortedLatenciesMs, 0.50)),
            'p95_ms' => self::roundMs(self::percentile($sortedLatenciesMs, 0.95)),
            'p99_ms' => self::roundMs(self::percentile($sortedLatenciesMs, 0.99)),
            'samples_ms' => self::selectSamples($latenciesMs, $samplesMode),
            'meta' => [
                'php' => PHP_VERSION,
                'gaze_version' => self::gazeVersion($resolver, $process),
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('mode', 'cold');
        $this->components->twoColumnDetail('requests', (string) $requests);
        $this->components->twoColumnDetail('first_ms', number_format((float) $payload['first_ms'], 3));
        $this->components->twoColumnDetail('total_wall_ms', number_format((float) $payload['total_wall_ms'], 3));
        $this->components->twoColumnDetail('p50_ms', number_format((float) $payload['p50_ms'], 3));
        $this->components->twoColumnDetail('p95_ms', number_format((float) $payload['p95_ms'], 3));
        $this->components->twoColumnDetail('p99_ms', number_format((float) $payload['p99_ms'], 3));

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function percentile(array $sorted, float $q): float
    {
        $count = count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $rank = $q * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + (($rank - $lower) * ($sorted[$upper] - $sorted[$lower]));
    }

    /**
     * @param  list<float>  $samples
     * @return list<float>
     */
    private static function selectSamples(array $samples, string $mode): array
    {
        if ($mode === 'none') {
            return [];
        }

        if ($mode === 'full' || count($samples) < 1000) {
            return array_map(self::roundMs(...), $samples);
        }

        return array_map(
            self::roundMs(...),
            array_merge(array_slice($samples, 0, 100), array_slice($samples, -100)),
        );
    }

    private static function roundMs(float $value): float
    {
        return round($value, 3);
    }

    private static function gazeVersion(BinaryResolver $resolver, ProcessFactory $process): string
    {
        try {
            $binary = $resolver->resolve();
            $result = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);
        } catch (GazeException) {
            return 'unknown';
        }

        if (! $result->successful()) {
            return 'unknown';
        }

        $version = trim($result->output());

        return $version === '' ? 'unknown' : $version;
    }
}
