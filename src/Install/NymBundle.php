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
 *  - a missing directory or required file, or a required file it cannot read;
 *  - a symlink anywhere in the tree, or anything that is neither a regular
 *    file nor a directory (a fifo, a socket);
 *  - any path not owned by the EFFECTIVE uid of the gaze process;
 *  - a directory whose mode is not exactly 0700;
 *  - a group- or world-writable file;
 *  - a SHA-256 mismatch against the pinned digests.
 *
 * This class mirrors every check except the digests. Hashing the 139 MB model
 * on each doctor run is the binary's job, and `gaze:doctor --deep` runs it. It
 * downloads nothing: `gaze setup --safety-net nym` owns the fetch.
 *
 * The owner checks compare against one uid: by default the uid of the PHP
 * process running the check. That process is often not the PHP-FPM pool or
 * queue worker user that spawns gaze in production, so callers either tell
 * the adopter to run the check as that user or judge for a named uid
 * ({@see self::forUid()}, the installer's `--runtime-user`). Without
 * ext-posix and without a named uid the owner checks are skipped, and
 * {@see self::ownerChecked()} says so.
 */
final class NymBundle
{
    /** Every file an installed bundle contains (upstream `REQUIRED_NYM_SMALL_ARTIFACTS`). */
    public const REQUIRED = ['SHA256SUMS', 'config.json', 'model_int8.onnx', 'tokenizer.json'];

    /** Where `gaze setup --safety-net nym` installs, below `$XDG_DATA_HOME`. */
    public const SETUP_SUBDIR = 'gaze/models/nym-small-int8';

    /** `$XDG_DATA_HOME` used in the setup hint when the target dir does not imply one. */
    public const EXAMPLE_DATA_HOME = '/srv/gaze';

    /** The environment variable upstream falls back to when `--nym-model-dir` is absent. */
    public const ENV = 'GAZE_NYM_MODEL_DIR';

    /** Why a set-but-empty {@see self::ENV} breaks every clean, and the fix. */
    public const EMPTY_ENV = 'GAZE_NYM_MODEL_DIR is set but empty. gaze uses it as is: it does not fall back to '
        .'the policy, and every clean fails with SafetyNetArtifactMissing. Remove the GAZE_NYM_MODEL_DIR= line '
        .'from .env (or the environment), or set it to the bundle directory.';

    /** Runtime user named in hints when the adopter has not named one. */
    public const EXAMPLE_USER = 'www-data';

    /**
     * Starter policy that `gaze setup` insists on writing, below the data
     * home. The adapter never reads it.
     */
    public const SETUP_POLICY = 'gaze-setup.toml';

    /**
     * @param  int|null  $euid  uid the owner checks judge against; null means
     *                          the uid of this process (`posix_geteuid()`)
     * @param  bool|null  $posix  whether ext-posix is loaded; null detects it.
     *                            A test seam for hosts without ext-posix.
     */
    public function __construct(
        private readonly ?int $euid = null,
        private readonly ?bool $posix = null,
    ) {}

    /** The same checks, judged for `$uid` (the installer's `--runtime-user`). */
    public function forUid(int $uid): self
    {
        return new self($uid, $this->posix);
    }

    /**
     * The bundle directory gaze will use, in upstream's precedence order:
     *
     *  1. `gaze.safety_net.nym.model_dir`, forwarded as `--nym-model-dir`;
     *  2. `GAZE_NYM_MODEL_DIR` in the process environment, which the gaze
     *     subprocess inherits ({@see self::envModelDir()}). Set but EMPTY
     *     still wins: the dir is then `''` and the policy is never read;
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

        $env = self::envModelDir();
        if ($env !== null) {
            return ['dir' => $env, 'source' => self::ENV.' (process environment)'];
        }

        $fromPolicy = $policyPath !== null ? self::policyModelDir($policyPath) : null;
        if ($fromPolicy !== null) {
            return ['dir' => $fromPolicy, 'source' => 'policy [safety_net.nym] model_dir'];
        }

        return null;
    }

    /**
     * `GAZE_NYM_MODEL_DIR` as a spawned gaze inherits it, or null when unset.
     *
     * `Gaze::clean()` spawns through Symfony Process, which hands the child
     * `$_ENV` first and then getenv(); the daemon's proc_open hands it the
     * real environment (getenv()). Laravel's dotenv loader fills both, so an
     * `.env` line `GAZE_NYM_MODEL_DIR=` reaches gaze as an empty value.
     * Upstream reads the variable with `var_os` and takes any set value as is,
     * empty included, so an empty string is returned here, not null.
     * `$_SERVER` is not a source: Symfony only passes getenv() values.
     */
    public static function envModelDir(): ?string
    {
        $value = array_key_exists(self::ENV, $_ENV) ? $_ENV[self::ENV] : getenv(self::ENV);

        return is_string($value) ? $value : null;
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
     * Reasons the gaze binary would refuse `$dir`, judged for
     * {@see self::effectiveUid()}. An empty list means every check passed.
     * A directory this process cannot read is a problem here: doctor must
     * see every file before it says OK.
     *
     * @return list<string>
     */
    public function problems(string $dir): array
    {
        $report = $this->inspect($dir);

        return $report['unread'] ? [...$report['problems'], $this->unreadMessage()] : $report['problems'];
    }

    /**
     * Like {@see self::problems()}, but a directory this process cannot read
     * is reported apart, as `unread`, instead of as a problem. That happens
     * when the checks judge for another user (`--runtime-user`): a 0700
     * bundle owned by that user is closed to everyone else, so only the
     * directory itself could be checked. The caller decides whether to defer
     * the rest to `gaze:doctor` run as that user.
     *
     * @return array{problems: list<string>, unread: bool}
     */
    public function inspect(string $dir): array
    {
        clearstatcache();

        if ($dir === '') {
            return ['problems' => ['the bundle path is empty'], 'unread' => false];
        }
        if (! str_starts_with($dir, '/')) {
            return ['problems' => ["{$dir} is a relative path; gaze resolves it against the worker's working directory, so use an absolute path"], 'unread' => false];
        }
        if (is_link($dir)) {
            return ['problems' => ["{$dir} is a symlink; gaze refuses symlinks, so point at the real directory"], 'unread' => false];
        }
        if (! file_exists($dir)) {
            return ['problems' => ["{$dir} does not exist"], 'unread' => false];
        }
        if (! is_dir($dir)) {
            return ['problems' => ["{$dir} is not a directory"], 'unread' => false];
        }

        $problems = [];
        $euid = $this->effectiveUid();

        $owner = fileowner($dir);
        if ($euid !== null && $owner !== $euid) {
            $problems[] = 'the directory is owned by '.self::userLabel($owner === false ? null : $owner)
                .', checked as '.self::userLabel($euid);
        }

        $mode = fileperms($dir);
        if ($mode !== false && ($mode & 0777) !== 0700) {
            $problems[] = sprintf('the directory mode is %04o; gaze requires exactly 0700', $mode & 0777);
        }

        if (! is_readable($dir) || ! is_executable($dir)) {
            return ['problems' => $problems, 'unread' => true];
        }

        // Upstream reads every required file (a directory in its place reads
        // as missing). A symlink or a fifo is left to the tree walk, which
        // names it once.
        $missing = [];
        $unreadable = [];
        foreach (self::REQUIRED as $name) {
            $path = $dir.'/'.$name;
            if (is_link($path)) {
                continue;
            }
            if (! file_exists($path) || is_dir($path)) {
                $missing[] = $name;
            } elseif (is_file($path) && ! $this->readableFor($path, $euid)) {
                $unreadable[] = sprintf('%s (%04o)', $name, (int) fileperms($path) & 0777);
            }
        }
        if ($missing !== []) {
            $problems[] = 'required files are missing: '.implode(', ', $missing);
        }
        if ($unreadable !== []) {
            $problems[] = 'required files are not readable by '.self::userLabel($euid).': '.implode(', ', $unreadable);
        }

        return ['problems' => [...$problems, ...$this->treeProblems($dir, $euid)], 'unread' => false];
    }

    /** Why {@see self::inspect()} reported `unread`. */
    public function unreadMessage(): string
    {
        return 'the directory is not readable by '.self::userLabel($this->processUid()).', so its files cannot be checked';
    }

    /**
     * The exact `gaze setup` command that fetches the bundle. Run it as the
     * user that runs gaze in production; upstream installs the bundle at
     * `$XDG_DATA_HOME/gaze/models/nym-small-int8` (it has no flag for the Nym
     * directory), so the data home is derived from `$modelDir` when that path
     * ends in the standard layout.
     *
     * `setup` also writes a starter policy, and checks that path only after
     * the downloads, so a fixed path that already exists fails a re-run late.
     * It goes below the data home (which the runtime user owns) with
     * `--force`, which in gaze 0.15 only lets that one file be overwritten.
     *
     * @param  string  $user  runtime user name, or a numeric uid
     */
    public static function setupCommand(string $binary, ?string $modelDir = null, string $user = self::EXAMPLE_USER): string
    {
        $dataHome = self::dataHomeFor($modelDir) ?? self::EXAMPLE_DATA_HOME;

        return 'sudo -u '.self::sudoUser($user).' env XDG_DATA_HOME='.self::shellArg($dataHome).' '.self::shellArg($binary)
            .' setup --safety-net nym --non-interactive --policy-out '.self::shellArg(self::setupPolicy($modelDir)).' --force';
    }

    /** Where {@see self::setupCommand()} writes the starter policy the adapter does not use. */
    public static function setupPolicy(?string $modelDir = null): string
    {
        return (self::dataHomeFor($modelDir) ?? self::EXAMPLE_DATA_HOME).'/'.self::SETUP_POLICY;
    }

    /**
     * Hands an existing bundle to the runtime user with the modes gaze
     * requires: no group- or world-writable path, every directory 0700.
     *
     * @param  string  $user  runtime user name, or a numeric uid
     */
    public static function chownCommand(string $modelDir, string $user = self::EXAMPLE_USER): string
    {
        $dir = self::shellArg($modelDir);
        $owner = self::shellArg($user);

        return "sudo chown -R {$owner} {$dir} && sudo chmod -R go-w {$dir} && sudo find {$dir} -type d -exec chmod 700 {} +";
    }

    /**
     * The installer run that wires the bundle {@see self::setupCommand()}
     * fetched, judged for the runtime user whoever runs it.
     *
     * @param  string  $user  runtime user name, or a numeric uid
     */
    public static function installCommand(?string $modelDir = null, string $user = self::EXAMPLE_USER): string
    {
        return 'php artisan gaze:install:safety-net --safety-net=nym --nym-model-dir='.self::shellArg(self::setupTarget($modelDir))
            .' --runtime-user='.self::shellArg($user);
    }

    /** `sudo -u <user> php artisan gaze:doctor`: the check that holds for the runtime user. */
    public static function doctorCommand(string $user = self::EXAMPLE_USER): string
    {
        return 'sudo -u '.self::sudoUser($user).' php artisan gaze:doctor';
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
        $special = [];
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
                if (! $info->isDir() && ! $info->isFile()) {
                    $special[] = $relative; // fifo, socket, device

                    continue;
                }

                $mode = $info->getPerms() & 0777;
                if ($info->isDir() && $mode !== 0700) {
                    $looseDirs[] = sprintf('%s (%04o)', $relative, $mode);
                } elseif ($info->isFile() && ($mode & 0022) !== 0) {
                    $writable[] = sprintf('%s (%04o)', $relative, $mode);
                }
            }
        } catch (\UnexpectedValueException) {
            return ['a subdirectory is not readable by '.self::userLabel($this->processUid())];
        }

        // Directory order is filesystem-dependent; sort for stable messages.
        sort($symlinks);
        sort($special);
        sort($foreign);
        sort($looseDirs);
        sort($writable);

        $problems = [];
        if ($symlinks !== []) {
            $problems[] = 'symlinks are not allowed: '.implode(', ', $symlinks);
        }
        if ($special !== []) {
            $problems[] = 'only regular files and directories are allowed: '.implode(', ', $special);
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

    /**
     * Whether the user gaze runs as can read `$path`. When that user is this
     * process, the kernel answers. When it is another user (the installer's
     * `--runtime-user`, run as root say), every path must be owned by that
     * user anyway, so the owner read bit decides; root reads any file.
     */
    private function readableFor(string $path, ?int $euid): bool
    {
        if ($euid === null || $euid === $this->processUid()) {
            return is_readable($path);
        }
        if ($euid === 0) {
            return true;
        }

        $perms = fileperms($path);

        return $perms !== false && ($perms & 0400) !== 0;
    }

    /**
     * The uid the checks judge ownership against: the named one, else this
     * process's. Null without ext-posix and without a named uid.
     */
    public function effectiveUid(): ?int
    {
        return $this->euid ?? $this->processUid();
    }

    /** False when the owner checks were skipped (no ext-posix, no named uid). */
    public function ownerChecked(): bool
    {
        return $this->effectiveUid() !== null;
    }

    /** The effective uid of this PHP process; null without ext-posix. */
    public function processUid(): ?int
    {
        return ($this->posix ?? function_exists('posix_geteuid')) ? posix_geteuid() : null;
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

    /** A numeric uid needs sudo's `#` prefix, quoted so the shell keeps it. */
    private static function sudoUser(string $user): string
    {
        return self::shellArg(ctype_digit($user) ? '#'.$user : $user);
    }

    private static function shellArg(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_./:=@%+,-]+$#', $value) === 1 ? $value : escapeshellarg($value);
    }
}
