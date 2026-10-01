<?php

declare(strict_types=1);

use CertaMesh\Gaze\GazeOptions;

it('defaults every knob to the omit-the-flag state', function () {
    $options = GazeOptions::fromConfig([]);

    expect($options->timeoutSeconds)->toBe(30)
        ->and($options->maxBytes)->toBeNull()
        ->and($options->sessionTtlSeconds)->toBeNull()
        ->and($options->auditDbPath)->toBeNull()
        ->and($options->locale)->toBeNull()
        ->and($options->rulepacks)->toBeNull()
        ->and($options->rulepackPaths)->toBeNull()
        ->and($options->safetyNet)->toBeFalse()
        ->and($options->safetyNetMode)->toBeNull()
        ->and($options->restoreMode)->toBeNull()
        ->and($options->restoreTelemetry)->toBeFalse()
        ->and($options->nerThreshold)->toBeNull();
});

it('coerces env-shaped strings into typed values', function () {
    $options = GazeOptions::fromConfig([
        'timeout_seconds' => '45',
        'max_bytes' => '2048',
        'session_ttl_seconds' => '600',
        'ner_threshold' => '0.85',
        'safety_net' => '1',
        'safety_net_timeout_ms' => '7500',
        'restore_telemetry' => '1',
    ]);

    expect($options->timeoutSeconds)->toBe(45)
        ->and($options->maxBytes)->toBe(2048)
        ->and($options->sessionTtlSeconds)->toBe(600)
        ->and($options->nerThreshold)->toBe(0.85)
        ->and($options->safetyNet)->toBeTrue()
        ->and($options->safetyNetTimeoutMs)->toBe(7500)
        ->and($options->restoreTelemetry)->toBeTrue();
});

it('normalizes empty strings to null so their flags are omitted', function () {
    $options = GazeOptions::fromConfig([
        'audit_db_path' => '',
        'locale' => '',
        'session_scope' => '',
        'safety_net_mode' => '',
        'max_bytes' => '',
    ]);

    expect($options->auditDbPath)->toBeNull()
        ->and($options->locale)->toBeNull()
        ->and($options->sessionScope)->toBeNull()
        ->and($options->safetyNetMode)->toBeNull()
        ->and($options->maxBytes)->toBeNull();
});

it('drops non-string rulepack entries and nulls empty lists', function () {
    expect(GazeOptions::fromConfig(['rulepacks' => ['names', 42, 'emails']])->rulepacks)
        ->toBe(['names', 'emails'])
        ->and(GazeOptions::fromConfig(['rulepacks' => []])->rulepacks)->toBeNull()
        ->and(GazeOptions::fromConfig(['rulepacks' => 'names'])->rulepacks)->toBeNull();
});

it('reads the deprecated flat safety-net keys (pre-v0.13 published configs)', function () {
    $options = GazeOptions::fromConfig([
        'safety_net' => true,
        'safety_net_backend' => 'nym',
        'safety_net_device' => 'cuda:0',
        'safety_net_timeout_ms' => 7500,
        'safety_net_input_limit_bytes' => 123456,
        'safety_net_mode' => 'tolerant',
        'safety_net_fallback' => 'redact',
        'openai_filter_command' => '/usr/local/bin/opf',
        'openai_filter_checkpoint' => '/models/opf',
        'openai_filter_operating_point' => 'high-recall',
    ]);

    expect($options->safetyNet)->toBeTrue()
        ->and($options->safetyNetBackend)->toBe('nym')
        ->and($options->safetyNetDevice)->toBe('cuda:0')
        ->and($options->safetyNetTimeoutMs)->toBe(7500)
        ->and($options->safetyNetInputLimitBytes)->toBe(123456)
        ->and($options->safetyNetMode)->toBe('tolerant')
        ->and($options->safetyNetFallback)->toBe('redact')
        ->and($options->openaiFilterCommand)->toBe('/usr/local/bin/opf')
        ->and($options->openaiFilterCheckpoint)->toBe('/models/opf')
        ->and($options->openaiFilterOperatingPoint)->toBe('high-recall');
});

it('reads the nested safety_net group shipped since v0.13', function () {
    $options = GazeOptions::fromConfig([
        'safety_net' => [
            'enabled' => true,
            'backend' => 'nym',
            'device' => 'cuda:0',
            'timeout_ms' => '7500',
            'input_limit_bytes' => '123456',
            'mode' => 'tolerant',
            'fallback' => 'redact',
            'openai_filter' => [
                'command' => '/usr/local/bin/opf',
                'checkpoint' => '/models/opf',
                'operating_point' => 'high-recall',
            ],
        ],
    ]);

    expect($options->safetyNet)->toBeTrue()
        ->and($options->safetyNetBackend)->toBe('nym')
        ->and($options->safetyNetDevice)->toBe('cuda:0')
        ->and($options->safetyNetTimeoutMs)->toBe(7500)
        ->and($options->safetyNetInputLimitBytes)->toBe(123456)
        ->and($options->safetyNetMode)->toBe('tolerant')
        ->and($options->safetyNetFallback)->toBe('redact')
        ->and($options->openaiFilterCommand)->toBe('/usr/local/bin/opf')
        ->and($options->openaiFilterCheckpoint)->toBe('/models/opf')
        ->and($options->openaiFilterOperatingPoint)->toBe('high-recall');
});

it('ignores leftover Kiji keys in both config shapes (removed upstream in gaze 0.15.0)', function () {
    $kiji = [
        'backend' => 'ort',
        'distilbert_precision' => 'int8',
        'distilbert_command' => '/usr/local/bin/kiji',
        'distilbert_model_dir' => '/var/lib/gaze/models/kiji',
    ];

    $nested = GazeOptions::fromConfig(['safety_net' => ['enabled' => true, 'kiji' => $kiji]]);
    $flat = GazeOptions::fromConfig([
        'safety_net' => true,
        'kiji_backend' => 'ort',
        'kiji_distilbert_precision' => 'int8',
        'kiji_distilbert_command' => '/usr/local/bin/kiji',
        'kiji_distilbert_model_dir' => '/var/lib/gaze/models/kiji',
    ]);

    // Same options as a config without them — nothing Kiji-shaped survives.
    expect($nested)->toEqual(GazeOptions::fromConfig(['safety_net' => ['enabled' => true]]))
        ->and($flat)->toEqual(GazeOptions::fromConfig(['safety_net' => true]))
        ->and(array_filter(
            array_keys(get_object_vars($nested)),
            fn (string $property): bool => str_starts_with($property, 'kiji'),
        ))->toBe([]);
});

it('lets a nested key win over its deprecated flat counterpart', function () {
    $options = GazeOptions::fromConfig([
        'safety_net' => ['enabled' => true, 'mode' => 'strict'],
        'safety_net_mode' => 'tolerant',
    ]);

    expect($options->safetyNetMode)->toBe('strict');
});

it('falls back to flat keys for values the nested group leaves null', function () {
    $options = GazeOptions::fromConfig([
        'safety_net' => ['enabled' => false, 'mode' => null],
        'safety_net_mode' => 'tolerant',
        'safety_net_fallback' => 'redact',
    ]);

    expect($options->safetyNet)->toBeFalse()
        ->and($options->safetyNetMode)->toBe('tolerant')
        ->and($options->safetyNetFallback)->toBe('redact');
});
