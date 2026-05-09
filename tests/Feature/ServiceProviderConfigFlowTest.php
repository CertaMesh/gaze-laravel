<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Naoray\GazeLaravel\Gaze;

it('Gaze resolved from container forwards OpenAI privacy-filter config on clean argv', function () {
    config([
        'gaze.binary' => '/fake/gaze',
        'gaze.openai_filter_command' => '/usr/local/bin/opf',
        'gaze.openai_filter_checkpoint' => '/models/openai-filter',
        'gaze.openai_filter_operating_point' => 'high-precision',
        'gaze.safety_net_timeout_ms' => 7500,
        'gaze.safety_net_input_limit_bytes' => 123456,
        'gaze.safety_net_mode' => 'tolerant',
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->app->make(Gaze::class)->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--openai-filter-command=/usr/local/bin/opf')
            ->toContain('--openai-filter-checkpoint=/models/openai-filter')
            ->toContain('--openai-filter-operating-point=high-precision')
            ->toContain('--safety-net-timeout-ms=7500')
            ->toContain('--safety-net-input-limit-bytes=123456')
            ->toContain('--safety-net-mode=tolerant');

        return true;
    });
});
