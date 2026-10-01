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

it('reads the nested nym group and coerces env-shaped strings', function () {
    $options = GazeOptions::fromConfig([
        'safety_net' => [
            'enabled' => 'true',
            'backend' => 'nym',
            'nym' => ['model_dir' => '/srv/nym', 'intra_threads' => '4'],
        ],
    ]);

    expect($options->nymModelDir)->toBe('/srv/nym')
        ->and($options->nymIntraThreads)->toBe(4)
        ->and($options->nymSelected())->toBeTrue();
});

it('reads the provider back-filled flat nym keys, with the nested group winning', function () {
    $flat = GazeOptions::fromConfig(['nym_model_dir' => '/srv/flat', 'nym_intra_threads' => 2]);
    $both = GazeOptions::fromConfig([
        'safety_net' => ['enabled' => true, 'nym' => ['model_dir' => '/srv/nested', 'intra_threads' => null]],
        'nym_model_dir' => '/srv/flat',
        'nym_intra_threads' => 2,
    ]);

    expect($flat->nymModelDir)->toBe('/srv/flat')
        ->and($flat->nymIntraThreads)->toBe(2)
        ->and($both->nymModelDir)->toBe('/srv/nested')
        ->and($both->nymIntraThreads)->toBe(2);
});

it('defaults the nym knobs to null and treats empty env strings as unset', function () {
    $options = GazeOptions::fromConfig(['safety_net' => ['nym' => ['model_dir' => '', 'intra_threads' => '']]]);

    expect(GazeOptions::fromConfig([])->nymModelDir)->toBeNull()
        ->and(GazeOptions::fromConfig([])->nymIntraThreads)->toBeNull()
        ->and($options->nymModelDir)->toBeNull()
        ->and($options->nymIntraThreads)->toBeNull();
});

it('selects nym only for an enabled net with the exact nym backend', function (bool $enabled, ?string $backend, bool $selected) {
    expect((new GazeOptions(safetyNet: $enabled, safetyNetBackend: $backend))->nymSelected())->toBe($selected);
})->with([
    'enabled nym' => [true, 'nym', true],
    'disabled nym' => [false, 'nym', false],
    'enabled openai-filter' => [true, 'openai-filter', false],
    'enabled default backend' => [true, null, false],
    'mis-cased (upstream rejects it)' => [true, 'Nym', false],
]);

it('appends the nym properties after every existing constructor parameter (positional BC)', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(GazeOptions::class, '__construct'))->getParameters(),
    );

    expect(array_slice($parameters, -4))->toBe(['nerThreshold', 'nymModelDir', 'nymIntraThreads', 'invalidNymIntraThreads']);
});

it('reads intra_threads strictly: integers only, anything else kept for the guard to name', function (mixed $value, ?int $threads, ?string $invalid) {
    $options = GazeOptions::fromConfig(['safety_net' => ['nym' => ['intra_threads' => $value]]]);

    expect($options->nymIntraThreads)->toBe($threads)
        ->and($options->invalidNymIntraThreads)->toBe($invalid);
})->with([
    'int' => [2, 2, null],
    'digit string' => ['4', 4, null],
    'signed digit string' => ['-1', -1, null],
    'padded digit string' => [' 3 ', 3, null],
    'unset' => [null, null, null],
    'empty env string' => ['', null, null],
    'decimal string' => ['1.5', null, "'1.5'"],
    'float' => [2.0, null, '2.0'],
    'word' => ['abc', null, "'abc'"],
    'exponent' => ['1e3', null, "'1e3'"],
    'bool' => [true, null, 'true'],
    'array' => [[2], null, 'array'],
]);
