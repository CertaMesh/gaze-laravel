<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Install;

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

        foreach ($set->files as $destName => $entry) {
            $target = $stagingDir.DIRECTORY_SEPARATOR.$destName;
            $tmp = $target.'.part.'.bin2hex(random_bytes(4));

            try {
                if (($entry['source'] ?? null) === 'package') {
                    $this->copyPackageArtifact($entry['sourceName'] ?? $destName, $tmp);
                } else {
                    $this->downloadRemoteArtifact($set->urlBase, $entry['sourceName'] ?? $destName, $tmp);
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

    private function downloadRemoteArtifact(string $urlBase, string $sourceName, string $tmp): void
    {
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
                if ($response instanceof StreamableInterface) {
                    $in = $response->toStream();
                    if (stream_copy_to_stream($in, $out) === false) {
                        throw new NerTransportException("could not stream NER artifact: {$url}");
                    }
                } else {
                    fwrite($out, $response->getContent());
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
}
