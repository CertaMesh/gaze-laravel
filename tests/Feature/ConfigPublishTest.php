<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Naoray\GazeLaravel\Tests\TestCase;

final class ConfigPublishTest extends TestCase
{
    public function test_config_publishes_to_application_config_path(): void
    {
        $target = $this->app->configPath('gaze.php');
        @unlink($target);

        Artisan::call('vendor:publish', [
            '--tag' => 'gaze-config',
            '--force' => true,
        ]);

        self::assertFileExists($target);
        $published = require $target;
        self::assertIsArray($published);
        self::assertArrayHasKey('binary', $published);
        self::assertArrayHasKey('timeout_seconds', $published);
        self::assertArrayHasKey('fail_closed', $published);

        @unlink($target);
    }
}
