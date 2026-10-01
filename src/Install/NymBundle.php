<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Devium\Toml\Toml;

/**
 * Pre-flight checks for the pinned Nym-small int8 bundle (gaze >= 0.15.0).
 *
 * Single source of truth shared by `gaze:install:safety-net` (checked before
 * `.env` is written) and `gaze:doctor` (the Nym probe), so the installer never
 * writes a wiring that doctor would then fail.
 *
 * Upstream verifies the bundle on every clean / daemon start and refuses it
 * on (gaze-recognizers `bundle.rs`, gaze-assembly `attach_nym_safety_net`):
 *
 *  - a missing directory or required file;
 *  - a symlink anywhere in the tree;
 *  - any path not owned by the EFFECTIVE uid of the gaze process;
 *  - a directory whose mode is not exactly 0700;
 *  - a group- or world-writable file;
 *  - a SHA-256 mismatch against the pinned digests.
 *
 * This class mirrors every check except the digests. Hashing the 139 MB model
 * on each doctor run is the binary's job, and `gaze:doctor --deep` runs it. It
 * downloads nothing: `gaze setup --safety-net nym` owns the fetch.
 *
 * The owner checks compare against the uid of the PHP process running the
 * check. That process is often not the PHP-FPM pool or queue worker user that
 * spawns gaze in production, so callers tell the adopter to run the check as
 * that user. The constructor's `$euid` is a test seam; null reads
 * `posix_geteuid()`, and without ext-posix the owner checks are skipped.
 */
final class NymBundle
{
    /** Every file an installed bundle contains (upstream `REQUIRED_NYM_SMALL_ARTIFACTS`). */
    public const REQUIRED = ['SHA256SUMS', 'config.json', 'model_int8.onnx', 'tokenizer.json'];

    /** Where `gaze setup --safety-net nym` installs, below `$XDG_DATA_HOME`. */
    public const SETUP_SUBDIR = 'gaze/models/nym-small-int8';

    /** `$XDG_DATA_HOME` used in the setup hint when the target dir does not imply one. */
    public const EXAMPLE_DATA_HOME = '/srv/gaze';

    public function __construct(private readonly ?int $euid = null) {}

    /**
     * The bundle directory gaze will use, in upstream's precedence order:
     *
     *  1. `gaze.safety_net.nym.model_dir`, forwarded as `--nym-model-dir`;
     *  2. `GAZE_NYM_MODEL_DIR` in the process environment, which the gaze
     *     subprocess inherits;
     *  3. the policy's `[safety_net.nym] model_dir`.
     *
     * Null when none of them names a directory. An unreadable or unparseable
     * policy counts as naming none.
     *
     * @return array{dir: string, source: string}|null
     */
    public static function locate(?string $configured, ?string $policyPath): ?array
    {
        if ($configured !== null && $configured !== '') {
            return ['dir' => $configured, 'source' => 'gaze.safety_net.nym.model_dir'];
        }

        $env = getenv('GAZE_NYM_MODEL_DIR');
        if (is_string($env) && $env !== '') {
            return ['dir' => $env, 'source' => 'GAZE_NYM_MODEL_DIR (process environment)'];
        }

        $fromPolicy = $policyPath !== null ? self::policyModelDir($policyPath) : null;
        if ($fromPolicy !== null) {
            return ['dir' => $fromPolicy, 'source' => 'policy [safety_net.nym] model_dir'];
        }

        return null;
    }

    /**
     * The policy's `[safety_net.nym] model_dir`, or null when the file is
     * missing, unparseable, or has no such key.
     */
    public static function policyModelDir(string $policyPath): ?string
    {
        $body = @file_get_contents($policyPath);
        if ($body === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable) {
            return null;
        }

        $safetyNet = $parsed['safety_net'] ?? null;
        $nym = is_array($safetyNet) ? ($safetyNet['nym'] ?? null) : null;
        $dir = is_array($nym) ? ($nym['model_dir'] ?? null) : null;

        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    /**
     * Reasons the gaze binary would refuse `$dir`, judged as the current
     * process user. An empty list means every check passed.
     *
     * @return list<string>
     */
    public function problems(string $dir): array
    {
        clearstatcache();

        if (! str_starts_with($dir, '/')) {
            return ["{$dir} is a relative path; gaze resolves it against the worker's working directory, so use an absolute path"];
        }
        if (is_link($dir)) {
            return ["{$dir} is a symlink; gaze refuses symlinks, so point at the real directory"];
        }
        if (! file_exists($dir)) {
            return ["{$dir} does not exist"];
        }
        if (! is_dir($dir)) {
            return ["{$dir} is not a directory"];
        }

        $problems = [];
        $euid = $this->effectiveUid();

        $owner = fileowner($dir);
        if ($euid !== null && $owner !== $euid) {
            $problems[] = 'the directory is owned by '.self::userLabel($owner === false ? null : $owner)
                .', but gaze would run as '.self::userLabel($euid);
        }

        $mode = fileperms($dir);
        if ($mode !== false && ($mode & 0777) !== 0700) {
            $problems[] = sprintf('the directory mode is %04o; gaze requires exactly 0700', $mode & 0777);
        }

        if (! is_readable($dir) || ! is_executable($dir)) {
            $problems[] = 'the directory is not readable by '.self::userLabel($euid).', so its files cannot be checked';

            return $problems;
        }

        $missing = array_values(array_filter(
            self::REQUIRED,
            static fn (string $name): bool => ! is_file($dir.'/'.$name) || is_link($dir.'/'.$name),
        ));
        if ($missing !== []) {
            $problems[] = 'required files are missing: '.implode(', ', $missing);
        }

        return [...$problems, ...$this->treeProblems($dir, $euid)];
    }

    /**
     * The exact `gaze setup` command that fetches the bundle. Run it as the
     * user that runs gaze in production; upstream installs the bundle at
     * `$XDG_DATA_HOME/gaze/models/nym-small-int8` (it has no flag for the Nym
     * directory), so the data home is derived from `$modelDir` when that path
     * ends in the standard layout.
     */
    public static function setupCommand(string $binary, ?string $modelDir = null): string
    {
        $dataHome = self::dataHomeFor($modelDir) ?? self::EXAMPLE_DATA_HOME;

        return 'sudo -u www-data env XDG_DATA_HOME='.self::shellArg($dataHome).' '.self::shellArg($binary)
            .' setup --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml';
    }

    /** Hands an existing bundle to the runtime user, with the mode gaze requires. */
    public static function chownCommand(string $modelDir): string
    {
        $dir = self::shellArg($modelDir);

        return "sudo chown -R www-data {$dir} && sudo chmod 700 {$dir}";
    }

    /**
     * Where `gaze setup --safety-net nym` would have put the bundle for the
     * CURRENT user (`$XDG_DATA_HOME`, else `~/.local/share`), when that
     * directory exists. Only a prompt default: it is the right directory
     * when the installer runs as the runtime user.
     */
    public static function existingSetupDefault(): ?string
    {
        $dataHome = getenv('XDG_DATA_HOME');
        if (! is_string($dataHome) || $dataHome === '') {
            $home = getenv('HOME');
            $dataHome = is_string($home) && $home !== '' ? $home.'/.local/share' : null;
        }

        if ($dataHome === null) {
            return null;
        }

        $dir = rtrim($dataHome, '/').'/'.self::SETUP_SUBDIR;

        return is_dir($dir) ? $dir : null;
    }

    /** The bundle directory {@see self::setupCommand()} installs into. */
    public static function setupTarget(?string $modelDir = null): string
    {
        return (self::dataHomeFor($modelDir) ?? self::EXAMPLE_DATA_HOME).'/'.self::SETUP_SUBDIR;
    }

    /**
     * Walk the tree below `$dir` the way upstream does: no symlinks, every
     * path owned by the effective uid, subdirectories 0700, files not group-
     * or world-writable.
     *
     * @return list<string>
     */
    private function treeProblems(string $dir, ?int $euid): array
    {
        $symlinks = [];
        $foreign = [];
        $looseDirs = [];
        $writable = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        try {
            foreach ($iterator as $path => $info) {
                /** @var \SplFileInfo $info */
                $relative = substr((string) $path, strlen($dir) + 1);

                if ($info->isLink()) {
                    $symlinks[] = $relative;

                    continue;
                }
                if ($euid !== null && $info->getOwner() !== $euid) {
                    $foreign[] = $relative;
                }

                $mode = $info->getPerms() & 0777;
                if ($info->isDir() && $mode !== 0700) {
                    $looseDirs[] = sprintf('%s (%04o)', $relative, $mode);
                } elseif ($info->isFile() && ($mode & 0022) !== 0) {
                    $writable[] = sprintf('%s (%04o)', $relative, $mode);
                }
            }
        } catch (\UnexpectedValueException) {
            return ['a subdirectory is not readable by '.self::userLabel($euid)];
        }

        // Directory order is filesystem-dependent; sort for stable messages.
        sort($symlinks);
        sort($foreign);
        sort($looseDirs);
        sort($writable);

        $problems = [];
        if ($symlinks !== []) {
            $problems[] = 'symlinks are not allowed: '.implode(', ', $symlinks);
        }
        if ($foreign !== []) {
            $problems[] = 'not owned by '.self::userLabel($euid).': '.implode(', ', $foreign);
        }
        if ($looseDirs !== []) {
            $problems[] = 'subdirectories must be mode 0700: '.implode(', ', $looseDirs);
        }
        if ($writable !== []) {
            $problems[] = 'files must not be group- or world-writable: '.implode(', ', $writable);
        }

        return $problems;
    }

    /** The uid the checks judge ownership against; null without ext-posix. */
    public function effectiveUid(): ?int
    {
        if ($this->euid !== null) {
            return $this->euid;
        }

        return function_exists('posix_geteuid') ? posix_geteuid() : null;
    }

    /** `uid 33 (www-data)`, or `uid 33` without ext-posix. */
    public static function userLabel(?int $uid): string
    {
        if ($uid === null) {
            return 'the current user';
        }

        $entry = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return is_array($entry) ? "uid {$uid} ({$entry['name']})" : "uid {$uid}";
    }

    private static function dataHomeFor(?string $modelDir): ?string
    {
        if ($modelDir === null || ! str_starts_with($modelDir, '/')) {
            return null;
        }

        $suffix = '/'.self::SETUP_SUBDIR;
        $trimmed = rtrim($modelDir, '/');

        if (! str_ends_with($trimmed, $suffix) || strlen($trimmed) === strlen($suffix)) {
            return null;
        }

        return substr($trimmed, 0, -strlen($suffix));
    }

    private static function shellArg(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_./:=@%+,-]+$#', $value) === 1 ? $value : escapeshellarg($value);
    }
}
