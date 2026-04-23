<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Unit;

use Naoray\GazeLaravel\BinaryResolver;
use Naoray\GazeLaravel\Exceptions\GazeBinaryMissingException;
use PHPUnit\Framework\TestCase;

final class BinaryResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_explicit_path_wins_even_when_vendor_bin_exists(): void
    {
        $explicit = '/usr/local/bin/custom-ghostwriter';
        $vendor = $this->makeExecutable('ghostwriter');

        $resolver = new BinaryResolver(explicitPath: $explicit, vendorBinPath: $vendor);

        self::assertSame($explicit, $resolver->resolve());
    }

    public function test_empty_explicit_path_is_treated_as_unset(): void
    {
        $vendor = $this->makeExecutable('ghostwriter');

        $resolver = new BinaryResolver(explicitPath: '', vendorBinPath: $vendor);

        self::assertSame($vendor, $resolver->resolve());
    }

    public function test_vendor_bin_is_used_when_executable(): void
    {
        $vendor = $this->makeExecutable('ghostwriter');

        $resolver = new BinaryResolver(explicitPath: null, vendorBinPath: $vendor);

        self::assertSame($vendor, $resolver->resolve());
    }

    public function test_non_executable_vendor_path_falls_through(): void
    {
        $vendor = $this->tmpDir.'/not-executable';
        file_put_contents($vendor, 'stub');
        // Do not chmod — must not be executable.

        $resolver = new BinaryResolver(
            explicitPath: null,
            vendorBinPath: $vendor,
        );

        // With no real ghostwriter on PATH in CI, expect missing.
        // If a dev machine has it installed we'd get a path string back,
        // which is acceptable. Guard accordingly.
        try {
            $resolved = $resolver->resolve();
            self::assertIsString($resolved);
            self::assertNotSame($vendor, $resolved);
        } catch (GazeBinaryMissingException $e) {
            self::assertStringContainsString('ghostwriter binary not found', $e->getMessage());
        }
    }

    public function test_missing_everywhere_raises_binary_missing_exception(): void
    {
        $resolver = new BinaryResolver(
            explicitPath: null,
            vendorBinPath: $this->tmpDir.'/does-not-exist',
        );

        // Use a PATH where ghostwriter cannot be found.
        $originalPath = getenv('PATH');
        putenv('PATH='.$this->tmpDir);
        try {
            $this->expectException(GazeBinaryMissingException::class);
            $resolver->resolve();
        } finally {
            putenv('PATH='.($originalPath === false ? '' : $originalPath));
        }
    }

    private function makeExecutable(string $name): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, "#!/bin/sh\necho stub\n");
        chmod($path, 0755);

        return $path;
    }
}
