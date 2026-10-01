<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\NymBundle;

/*
 * Adapter-side mirror of upstream's Nym bundle checks (gaze-recognizers
 * bundle.rs, gaze-assembly attach_nym_safety_net): required files, owner ==
 * effective uid, directory mode exactly 0700, no group/world-writable files,
 * no symlinks. The owner checks run against an injected uid where the real
 * one cannot be changed without root.
 */

beforeEach(function () {
    $this->previousEnv = gl_stashNymEnv();
    $this->bundle = gl_makeNymBundle();
});

afterEach(function () {
    gl_restoreNymEnv($this->previousEnv);
    if (isset($this->bundle)) {
        gl_removeNymBundle($this->bundle);
    }
});

it('accepts a bundle shaped like `gaze setup --safety-net nym` leaves it', function () {
    expect((new NymBundle)->problems($this->bundle))->toBe([]);
});

it('names every required file the bundle lacks', function () {
    unlink($this->bundle.'/model_int8.onnx');
    unlink($this->bundle.'/SHA256SUMS');

    expect((new NymBundle)->problems($this->bundle))
        ->toBe(['required files are missing: SHA256SUMS, model_int8.onnx']);
});

it('requires the directory mode to be exactly 0700', function (int $mode) {
    chmod($this->bundle, $mode);

    expect((new NymBundle)->problems($this->bundle))
        ->toBe([sprintf('the directory mode is %04o; gaze requires exactly 0700', $mode)]);
})->with(['group-readable' => [0750], 'world-readable' => [0755], 'owner read-only' => [0500]]);

it('fails a directory owned by another uid than the one gaze runs as', function () {
    $owner = fileowner($this->bundle);
    $other = $owner + 4242;

    $problems = (new NymBundle(euid: $other))->problems($this->bundle);

    expect($problems[0])->toStartWith('the directory is owned by uid '.$owner)
        ->toEndWith(', but gaze would run as uid '.$other)
        ->and($problems[1])->toBe('not owned by uid '.$other.': '.implode(', ', nb_sortedRequired()));
});

it('checks the owner against posix_geteuid() by default', function () {
    expect((new NymBundle)->effectiveUid())->toBe(posix_geteuid());
})->skip(! function_exists('posix_geteuid'), 'ext-posix not available');

it('fails group- or world-writable files, symlinks and loose subdirectories', function () {
    chmod($this->bundle.'/config.json', 0620);
    symlink($this->bundle.'/tokenizer.json', $this->bundle.'/extra.json');
    mkdir($this->bundle.'/sub', 0755);
    chmod($this->bundle.'/sub', 0755);

    expect((new NymBundle)->problems($this->bundle))->toBe([
        'symlinks are not allowed: extra.json',
        'subdirectories must be mode 0700: sub (0755)',
        'files must not be group- or world-writable: config.json (0620)',
    ]);
});

it('fails a required file that is a symlink', function () {
    $real = $this->bundle.'/../'.basename($this->bundle).'-tokenizer.json';
    rename($this->bundle.'/tokenizer.json', $real);
    symlink($real, $this->bundle.'/tokenizer.json');

    try {
        expect((new NymBundle)->problems($this->bundle))->toBe([
            'required files are missing: tokenizer.json',
            'symlinks are not allowed: tokenizer.json',
        ]);
    } finally {
        @unlink($real);
    }
});

it('fails a missing, relative, non-directory or symlinked bundle path', function () {
    $link = $this->bundle.'-link';
    symlink($this->bundle, $link);

    try {
        expect((new NymBundle)->problems($this->bundle.'-gone'))->toBe([$this->bundle.'-gone does not exist'])
            ->and((new NymBundle)->problems('storage/nym'))->toBe(["storage/nym is a relative path; gaze resolves it against the worker's working directory, so use an absolute path"])
            ->and((new NymBundle)->problems($this->bundle.'/config.json'))->toBe([$this->bundle.'/config.json is not a directory'])
            ->and((new NymBundle)->problems($link))->toBe(["{$link} is a symlink; gaze refuses symlinks, so point at the real directory"]);
    } finally {
        @unlink($link);
    }
});

it('reports an unreadable directory instead of claiming its files are missing', function () {
    chmod($this->bundle, 0000);

    expect((new NymBundle)->problems($this->bundle))->toBe([
        'the directory mode is 0000; gaze requires exactly 0700',
        'the directory is not readable by '.NymBundle::userLabel(posix_geteuid()).', so its files cannot be checked',
    ]);
})->skip(! function_exists('posix_geteuid') || posix_geteuid() === 0, 'root reads 0000 directories');

it('locates the bundle dir in upstream precedence: config, process env, policy', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-');
    file_put_contents($policy, "[safety_net.nym]\nmodel_dir = \"/from/policy\"\n");

    try {
        expect(NymBundle::locate(null, $policy))->toBe(['dir' => '/from/policy', 'source' => 'policy [safety_net.nym] model_dir']);

        putenv('GAZE_NYM_MODEL_DIR=/from/env');
        expect(NymBundle::locate(null, $policy))->toBe(['dir' => '/from/env', 'source' => 'GAZE_NYM_MODEL_DIR (process environment)'])
            ->and(NymBundle::locate('/from/config', $policy))->toBe(['dir' => '/from/config', 'source' => 'gaze.safety_net.nym.model_dir']);
    } finally {
        @unlink($policy);
    }
});

it('treats a set-but-empty GAZE_NYM_MODEL_DIR as set, like upstream (no fallback to the policy)', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-');
    file_put_contents($policy, "[safety_net.nym]\nmodel_dir = \"/from/policy\"\n");

    try {
        putenv('GAZE_NYM_MODEL_DIR=');
        expect(NymBundle::envModelDir())->toBe('')
            ->and(NymBundle::locate(null, $policy))->toBe(['dir' => '', 'source' => 'GAZE_NYM_MODEL_DIR (process environment)'])
            ->and(NymBundle::locate('/from/config', $policy))->toBe(['dir' => '/from/config', 'source' => 'gaze.safety_net.nym.model_dir'])
            ->and((new NymBundle)->problems(''))->toBe(['the bundle path is empty']);
    } finally {
        @unlink($policy);
    }
});

it('reads GAZE_NYM_MODEL_DIR from $_ENV before getenv(), the way Symfony Process hands it to gaze', function () {
    $_ENV['GAZE_NYM_MODEL_DIR'] = '';
    putenv('GAZE_NYM_MODEL_DIR=/from/getenv');
    expect(NymBundle::envModelDir())->toBe('');

    $_ENV['GAZE_NYM_MODEL_DIR'] = '/from/dotenv';
    expect(NymBundle::envModelDir())->toBe('/from/dotenv');

    unset($_ENV['GAZE_NYM_MODEL_DIR']);
    expect(NymBundle::envModelDir())->toBe('/from/getenv');

    putenv('GAZE_NYM_MODEL_DIR');
    $_SERVER['GAZE_NYM_MODEL_DIR'] = '/from/server'; // Symfony never passes a $_SERVER-only value
    expect(NymBundle::envModelDir())->toBeNull();
});

it('locates nothing when no source names a directory', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-');
    file_put_contents($policy, "[safety_net]\nbackend = \"nym\"\n");

    try {
        expect(NymBundle::locate(null, $policy))->toBeNull()
            ->and(NymBundle::locate('', null))->toBeNull()
            ->and(NymBundle::locate(null, '/does/not/exist.toml'))->toBeNull()
            ->and(NymBundle::policyModelDir(dirname(__DIR__, 3).'/resources/policy.toml'))->toBeNull();

        file_put_contents($policy, "not = [valid toml\n");
        expect(NymBundle::policyModelDir($policy))->toBeNull();
    } finally {
        @unlink($policy);
    }
});

it('prints the gaze setup command for the runtime user, deriving XDG_DATA_HOME from a standard bundle path', function () {
    expect(NymBundle::setupCommand('/app/vendor/bin/gaze', '/var/lib/app/gaze/models/nym-small-int8'))
        ->toBe('sudo -u www-data env XDG_DATA_HOME=/var/lib/app /app/vendor/bin/gaze setup --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml')
        ->and(NymBundle::setupTarget('/var/lib/app/gaze/models/nym-small-int8/'))->toBe('/var/lib/app/gaze/models/nym-small-int8')
        ->and(NymBundle::setupCommand('/app/vendor/bin/gaze'))
        ->toBe('sudo -u www-data env XDG_DATA_HOME=/srv/gaze /app/vendor/bin/gaze setup --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml')
        ->and(NymBundle::setupTarget('/opt/custom-nym'))->toBe('/srv/gaze/gaze/models/nym-small-int8')
        ->and(NymBundle::setupCommand('/my apps/gaze', '/data home/gaze/models/nym-small-int8'))
        ->toBe("sudo -u www-data env XDG_DATA_HOME='/data home' '/my apps/gaze' setup --safety-net nym --non-interactive --policy-out /tmp/gaze-setup.toml")
        ->and(NymBundle::chownCommand('/srv/gaze/gaze/models/nym-small-int8'))
        ->toBe('sudo chown -R www-data /srv/gaze/gaze/models/nym-small-int8 && sudo chmod 700 /srv/gaze/gaze/models/nym-small-int8');
});

/** @return list<string> */
function nb_sortedRequired(): array
{
    $files = NymBundle::REQUIRED;
    sort($files);

    return $files;
}
