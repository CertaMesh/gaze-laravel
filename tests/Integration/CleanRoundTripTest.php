<?php

declare(strict_types=1);

use CertaMesh\Gaze\Gaze;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $this->markTestSkipped('GAZE_BINARY not set — integration tests skipped.');
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
});

it('round-trips clean then restore against the real binary', function () {
    $original = 'Hi Alice (alice@example.com), please confirm.';

    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean($original);

    expect($session->cleanText)->toContain('Alice')
        ->not->toContain('alice@example.com');

    $restored = $gaze->restore($session, $session->cleanText);

    expect($restored)->toContain('alice@example.com');
});

it('round-trips a Laravel-style JSON tool call with email phone and name', function () {
    $policyPath = sys_get_temp_dir().'/gaze-laravel-tool-call-policy-'.bin2hex(random_bytes(6)).'.toml';
    file_put_contents($policyPath, <<<'TOML'
[session]
scope = "persistent"
ttl_secs = 86400

[[policy.custom_recognizers]]
kind = "regex"
name = "fixture_email"
class = "email"
pattern = 'alice@example[.]invalid'

[[policy.custom_recognizers]]
kind = "regex"
name = "fixture_phone"
class = "custom:phone"
pattern = '[+]1[ ]555[ ]010[ ]4242'

[[policy.custom_recognizers]]
kind = "regex"
name = "fixture_name"
class = "name"
pattern = 'Alice Example'

[[rule]]
kind = "class"
class = "email"
action = "tokenize"

[[rule]]
kind = "class"
class = "custom:phone"
action = "tokenize"

[[rule]]
kind = "class"
class = "name"
action = "tokenize"

[[rule]]
kind = "default"
action = "preserve"
TOML);
    $this->app['config']->set('gaze.policy_path', $policyPath);

    $payload = json_encode([
        'tool' => 'crm.lookup_contact',
        'arguments' => [
            'name' => 'Alice Example',
            'email' => 'alice@example.invalid',
            'phone' => '+1 555 010 4242',
        ],
    ], JSON_THROW_ON_ERROR);

    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean($payload);

    expect($session->cleanText)
        ->not->toContain('Alice Example')
        ->not->toContain('alice@example.invalid')
        ->not->toContain('+1 555 010 4242');

    expect($gaze->restore($session, $session->cleanText))->toBe($payload);

    @unlink($policyPath);
});
