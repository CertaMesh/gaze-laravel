<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('downloads and verifies the live NER artifact set', function () {
    if (env('GAZE_LIVE_NER_SMOKE') !== '1') {
        $this->markTestSkipped('GAZE_LIVE_NER_SMOKE=1 not set; live HuggingFace smoke skipped.');
    }

    $dest = sys_get_temp_dir().'/gaze-live-ner-'.bin2hex(random_bytes(6));

    try {
        $exit = Artisan::call('gaze:install-ner', [
            '--dest' => $dest,
            '--force' => true,
            '--no-progress' => true,
        ]);

        expect($exit)->toBe(0);
        expect(is_file($dest.'/model.onnx'))->toBeTrue();
        expect(filesize($dest.'/model.onnx'))->toBeGreaterThan(100_000_000);

        $check = Artisan::call('gaze:install-ner', [
            '--dest' => $dest,
            '--check' => true,
            '--no-progress' => true,
        ]);

        expect($check)->toBe(0);
    } finally {
        if (is_dir($dest)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }

            @rmdir($dest);
        }
    }
});
