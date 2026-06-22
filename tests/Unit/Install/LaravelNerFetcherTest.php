<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\LaravelNerFetcher;
use CertaMesh\Gaze\Install\NerArtifactSet;
use CertaMesh\Gaze\Install\NerShaMismatchException;
use CertaMesh\Gaze\Install\NerTransportException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/gaze-fetcher-'.bin2hex(random_bytes(6));
    $this->resources = $this->tmp.'/resources';
    mkdir($this->resources, 0755, true);
    file_put_contents($this->resources.'/labels.davlan-mbert.json', '{"O":"drop"}');
});

afterEach(function () {
    if (! is_dir($this->tmp)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($this->tmp);
});

it('fetches remote artifacts and package labels into staging', function () {
    $remoteBytes = 'remote-tokenizer';
    $labelBytes = '{"O":"drop"}';
    $set = new NerArtifactSet(
        urlBase: 'https://example.test/models',
        files: [
            'tokenizer.json' => [
                'sha' => hash('sha256', $remoteBytes),
                'size' => strlen($remoteBytes),
                'sourceName' => 'tokenizer.json',
            ],
            'labels.json' => [
                'sha' => hash('sha256', $labelBytes),
                'size' => strlen($labelBytes),
                'source' => 'package',
                'sourceName' => 'labels.davlan-mbert.json',
            ],
        ],
    );
    $client = new MockHttpClient([new MockResponse($remoteBytes)]);
    $fetcher = new LaravelNerFetcher($client, $this->resources);
    $staging = $this->tmp.'/staging';

    $fetcher->fetch($set, $staging, new NullOutput);

    expect(file_get_contents($staging.'/tokenizer.json'))->toBe($remoteBytes);
    expect(file_get_contents($staging.'/labels.json'))->toBe($labelBytes);
    expect($fetcher->verify($set, $staging))->toBeTrue();
});

it('returns false from verify when a file is missing or mismatched', function () {
    $set = new NerArtifactSet(
        urlBase: 'https://example.test/models',
        files: [
            'tokenizer.json' => ['sha' => hash('sha256', 'expected'), 'size' => 8, 'sourceName' => 'tokenizer.json'],
        ],
    );
    $fetcher = new LaravelNerFetcher(new MockHttpClient, $this->resources);
    $staging = $this->tmp.'/staging';
    mkdir($staging);

    expect($fetcher->verify($set, $staging))->toBeFalse();

    file_put_contents($staging.'/tokenizer.json', 'wrong');

    expect($fetcher->verify($set, $staging))->toBeFalse();
});

it('rejects non-HTTPS artifact sources', function () {
    $set = new NerArtifactSet(
        urlBase: 'http://example.test/models',
        files: [
            'tokenizer.json' => ['sha' => hash('sha256', 'bytes'), 'size' => 5, 'sourceName' => 'tokenizer.json'],
        ],
    );
    $fetcher = new LaravelNerFetcher(new MockHttpClient([new MockResponse('bytes')]), $this->resources);

    expect(fn () => $fetcher->fetch($set, $this->tmp.'/staging', new NullOutput))
        ->toThrow(NerTransportException::class);
});

it('throws and leaves no artifact on SHA mismatch', function () {
    $set = new NerArtifactSet(
        urlBase: 'https://example.test/models',
        files: [
            'tokenizer.json' => ['sha' => hash('sha256', 'expected'), 'size' => 8, 'sourceName' => 'tokenizer.json'],
        ],
    );
    $fetcher = new LaravelNerFetcher(new MockHttpClient([new MockResponse('wrong')]), $this->resources);
    $staging = $this->tmp.'/staging';

    expect(fn () => $fetcher->fetch($set, $staging, new NullOutput))
        ->toThrow(NerShaMismatchException::class);
    expect(is_file($staging.'/tokenizer.json'))->toBeFalse();
});

it('passes HUGGINGFACE_TOKEN as an Authorization header', function () {
    $requests = [];
    $set = new NerArtifactSet(
        urlBase: 'https://example.test/models',
        files: [
            'tokenizer.json' => ['sha' => hash('sha256', 'bytes'), 'size' => 5, 'sourceName' => 'tokenizer.json'],
        ],
    );
    $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
        $requests[] = compact('method', 'url', 'options');

        return new MockResponse('bytes');
    });
    $fetcher = new LaravelNerFetcher($client, $this->resources);
    $original = getenv('HUGGINGFACE_TOKEN');
    putenv('HUGGINGFACE_TOKEN=test-token');

    try {
        $fetcher->fetch($set, $this->tmp.'/staging', new NullOutput);
    } finally {
        putenv($original === false ? 'HUGGINGFACE_TOKEN' : 'HUGGINGFACE_TOKEN='.$original);
    }

    expect($requests[0]['options']['normalized_headers']['authorization'][0] ?? null)
        ->toBe('Authorization: Bearer test-token');
});
