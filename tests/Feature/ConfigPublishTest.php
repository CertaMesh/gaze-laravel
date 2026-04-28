<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('publishes config to application config path', function () {
    $target = $this->app->configPath('gaze.php');
    @unlink($target);

    Artisan::call('vendor:publish', [
        '--tag' => 'gaze-config',
        '--force' => true,
    ]);

    expect($target)->toBeFile();

    $published = require $target;
    expect($published)->toBeArray()
        ->toHaveKeys(['binary', 'timeout_seconds', 'policy_path', 'blob_encryption_key', 'audit_db_path']);

    @unlink($target);
});
