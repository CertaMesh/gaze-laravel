<?php

declare(strict_types=1);

use CertaMesh\Gaze\Gaze;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $vendor = dirname(__DIR__, 2).'/vendor/bin/gaze';
        if (is_executable($vendor)) {
            $binary = $vendor;
        } else {
            $path = (new ExecutableFinder)->find('gaze');
            $binary = $path !== null ? $path : '';
        }
    }

    if ($binary === '') {
        $this->markTestSkipped('No gaze binary found (env GAZE_BINARY, vendor/bin/gaze, or PATH).');
    }

    $versionProcess = new Process([$binary, '--version']);
    $versionProcess->run();
    $versionOutput = trim($versionProcess->getOutput());
    if (! str_contains($versionOutput, '0.5.')) {
        $this->markTestSkipped("Gaze binary at {$binary} reports '{$versionOutput}', expected v0.5.x.");
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
});

it('tokenizes 5000€ whole - no leading-digit drop', function () {
    $session = $this->app->make(Gaze::class)->clean('Order total 5000€ due today.');

    expect($session->cleanText)
        ->not->toContain('5000€')
        ->not->toMatch('/\b5\b/')
        ->not->toMatch('/000€/');
});

it('tokenizes 1.500,00 EUR (DE thousand-sep + decimal)', function () {
    $session = $this->app->make(Gaze::class)->clean('Betrag 1.500,00 EUR fällig.');

    expect($session->cleanText)->not->toContain('1.500,00 EUR');
});

it('does NOT tokenize currency-shaped substrings inside identifiers', function () {
    $session = $this->app->make(Gaze::class)->clean('Marker abc5000EURdef in log.');

    expect($session->cleanText)->toContain('abc5000EURdef');
});

it('does NOT tokenize bare amounts without a currency token', function () {
    $session = $this->app->make(Gaze::class)->clean('Counter is at 1,500.00 hits.');

    expect($session->cleanText)->toContain('1,500.00');
});

it('tokenizes adjacent amounts with no spacing collapse', function () {
    $session = $this->app->make(Gaze::class)->clean('Posten: 5000€ 1000€ MwSt');

    expect($session->cleanText)
        ->not->toContain('5000€')
        ->not->toContain('1000€');

    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $session->cleanText);
    expect($normalized)->toBe('Posten: <AMOUNT> <AMOUNT> MwSt');
});

it('tokenizes amount adjacent to currency-prefixed amount', function () {
    $session = $this->app->make(Gaze::class)->clean('Total $100 €200 GBP300 due.');

    expect($session->cleanText)
        ->not->toContain('$100')
        ->not->toContain('€200')
        ->not->toContain('GBP300');

    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $session->cleanText);
    expect($normalized)->toBe('Total <AMOUNT> <AMOUNT> <AMOUNT> due.');
});
