<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LaravelNerFetcher implements NerFetcher
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $resourcesDir,
    ) {}

    public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
    {
        if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0755, true) && ! is_dir($stagingDir)) {
            throw new NerTransportException("could not create NER staging dir: {$stagingDir}");
        }

        $progress = $this->startProgress($set, $output);

        foreach ($set->files as $destName => $entry) {
            $target = $stagingDir.DIRECTORY_SEPARATOR.$destName;
            $tmp = $target.'.part.'.bin2hex(random_bytes(4));

            try {
                if (($entry['source'] ?? null) === 'package') {
                    $this->copyPackageArtifact($entry['sourceName'] ?? $destName, $tmp);
                    $progress?->advance($entry['size']);
                } else {
                    $this->downloadRemoteArtifact(
                        $set->urlBase,
                        $entry['sourceName'] ?? $destName,
                        $tmp,
                        $progress,
                        $entry['size'],
                    );
                }

                $this->assertSha($tmp, $destName, $entry['sha']);

                if (! rename($tmp, $target)) {
                    throw new NerTransportException("could not place NER artifact: {$destName}");
                }
            } catch (NerInstallException $e) {
                @unlink($tmp);
                @unlink($target);

                throw $e;
            } catch (\Throwable $e) {
                @unlink($tmp);
                @unlink($target);

                throw new NerTransportException("failed to fetch NER artifact {$destName}: ".$e->getMessage(), previous: $e);
            }
        }

        $this->finishProgress($progress, $output);
    }

    public function verify(NerArtifactSet $set, string $dir): bool
    {
        foreach ($set->files as $destName => $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$destName;
            if (! is_file($path) || hash_file('sha256', $path) !== $entry['sha']) {
                return false;
            }
        }

        return true;
    }

    private function downloadRemoteArtifact(
        string $urlBase,
        string $sourceName,
        string $tmp,
        ?ProgressBar $progress = null,
        int $expectedSize = 0,
    ): void {
        if (! str_starts_with($urlBase, 'https://')) {
            throw new NerTransportException("refusing non-HTTPS NER artifact base: {$urlBase}");
        }

        $url = rtrim($urlBase, '/').'/'.ltrim($sourceName, '/');
        $headers = [];
        $token = getenv('HUGGINGFACE_TOKEN');
        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => $headers,
                'max_redirects' => 5,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new NerTransportException("NER artifact request failed with HTTP {$status}: {$url}");
            }

            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new NerTransportException("could not open NER temp file: {$tmp}");
            }

            try {
                if ($response instanceof StreamableInterface && $progress !== null) {
                    // Chunked copy so the byte bar advances as data streams in.
                    $in = $response->toStream();
                    while (! feof($in)) {
                        $chunk = fread($in, 1 << 16);
                        if ($chunk === false) {
                            throw new NerTransportException("could not stream NER artifact: {$url}");
                        }
                        if ($chunk === '') {
                            continue;
                        }
                        fwrite($out, $chunk);
                        $progress->advance(strlen($chunk));
                    }
                } elseif ($response instanceof StreamableInterface) {
                    // No live bar: keep the efficient one-shot stream copy.
                    $in = $response->toStream();
                    if (stream_copy_to_stream($in, $out) === false) {
                        throw new NerTransportException("could not stream NER artifact: {$url}");
                    }
                } else {
                    fwrite($out, $response->getContent());
                    $progress?->advance($expectedSize);
                }
            } finally {
                fclose($out);
            }
        } catch (TransportExceptionInterface $e) {
            throw new NerTransportException("NER artifact transport failed: {$url}: ".$e->getMessage(), previous: $e);
        }
    }

    private function copyPackageArtifact(string $sourceName, string $tmp): void
    {
        $source = $this->resourcesDir.DIRECTORY_SEPARATOR.$sourceName;
        if (! is_file($source)) {
            throw new NerTransportException("missing packaged NER artifact: {$sourceName}");
        }

        if (! copy($source, $tmp)) {
            throw new NerTransportException("could not copy packaged NER artifact: {$sourceName}");
        }
    }

    private function assertSha(string $path, string $fileName, string $expected): void
    {
        $actual = hash_file('sha256', $path);
        if ($actual !== $expected) {
            throw new NerShaMismatchException($fileName, $expected, is_string($actual) ? $actual : '<unreadable>');
        }
    }

    /**
     * A real byte ProgressBar (total = the manifest's known artifact sizes, a
     * more reliable figure than a redirected mirror's Content-Length) on a
     * decorated TTY; a single plain line otherwise. NullOutput (the installer
     * default and the `--no-progress` path) swallows both, staying silent.
     */
    private function startProgress(NerArtifactSet $set, OutputInterface $output): ?ProgressBar
    {
        if (! $output->isDecorated()) {
            $output->writeln(sprintf(
                'Downloading NER model: %d files (~%s)...',
                count($set->files),
                $this->humanBytes($set->totalSize()),
            ));

            return null;
        }

        $bar = new ProgressBar($output, max(1, $set->totalSize()));
        $bar->setFormat(' downloading NER model  %current%/%max% bytes  [%bar%] %percent:3s%%');
        $bar->start();

        return $bar;
    }

    private function finishProgress(?ProgressBar $progress, OutputInterface $output): void
    {
        if ($progress !== null) {
            $progress->finish();
            $output->writeln('');
        }

        $output->writeln('NER model ready.');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
