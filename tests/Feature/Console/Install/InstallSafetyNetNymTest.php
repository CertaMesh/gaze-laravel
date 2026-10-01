<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Install\NymBundle;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;

/*
 * `gaze:install:safety-net --safety-net=nym` (#157): validates the bundle with
 * the same checks gaze:doctor runs BEFORE touching .env (CB4), and prints the
 * exact `gaze setup --safety-net nym` command when it is missing. It never
 * downloads the bundle itself.
 */

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../../../resources/policy.toml');
    // Hermetic against a GAZE_NYM_MODEL_DIR exported for the integration suite.
    $this->app['config']->set('gaze.nym_model_dir', null);
    $this->previousEnv = gl_stashNymEnv();

    $this->env = sys_get_temp_dir().'/gaze-env-'.bin2hex(random_bytes(6));
    file_put_contents($this->env, "APP_ENV=testing\n");
    $this->app->instance(SafetyNetConfigurator::class, new SafetyNetConfigurator($this->env));

    $this->bundle = gl_makeNymBundle();
});

afterEach(function () {
    gl_restoreNymEnv($this->previousEnv);
    @unlink($this->env);
    @unlink($this->env.'.backup');
    if (isset($this->bundle)) {
        gl_removeNymBundle($this->bundle);
    }
});

it('wires nym with the validated bundle dir and tells the adopter to run doctor as the pool user', function () {
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--no-interaction' => true])
        ->expectsOutputToContain('safety-net wired (nym)')
        ->expectsOutputToContain('Run `php artisan gaze:doctor` as the PHP-FPM pool / queue worker user.')
        ->expectsOutputToContain('use Gaze::daemon() for throughput')
        ->assertExitCode(0);

    expect(file_get_contents($this->env))->toBe(
        "APP_ENV=testing\nGAZE_SAFETY_NET=true\nGAZE_SAFETY_NET_BACKEND=nym\nGAZE_NYM_MODEL_DIR={$this->bundle}\n"
    );
});

it('does not touch .env when the bundle fails a check, and says how to fix it', function (Closure $break, string $problem) {
    $break($this->bundle);

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--no-interaction' => true])
        ->expectsOutputToContain("the Nym bundle at {$this->bundle} (--nym-model-dir) would be refused by gaze; .env was not changed:")
        ->expectsOutputToContain($problem)
        ->expectsOutputToContain('sudo -u www-data env XDG_DATA_HOME=/srv/gaze /fake/gaze setup --safety-net nym --non-interactive')
        ->assertExitCode(1);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n")
        ->and(is_file($this->env.'.backup'))->toBeFalse();
})->with([
    'required files missing' => [fn (string $dir) => unlink($dir.'/tokenizer.json'), 'required files are missing: tokenizer.json'],
    'directory not 0700' => [fn (string $dir) => chmod($dir, 0755), 'the directory mode is 0755; gaze requires exactly 0700'],
    'directory missing' => [fn (string $dir) => gl_removeNymBundle($dir), 'does not exist'],
]);

it('refuses a bundle another user owns, and points at --runtime-user instead of looping on chown', function () {
    // Seam: the installer runs as $processUid, the bundle belongs to someone
    // else (the real owner cannot be changed without root).
    $processUid = fileowner($this->bundle) + 4242;
    $this->app->instance(NymBundle::class, new NymBundle(euid: $processUid));

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--no-interaction' => true])
        ->expectsOutputToContain('would be refused by gaze; .env was not changed')
        ->expectsOutputToContain('the directory is owned by uid '.fileowner($this->bundle))
        ->expectsOutputToContain("(checked as uid {$processUid}; gaze checks the user that runs it)")
        ->expectsOutputToContain('sudo chown -R www-data '.$this->bundle.' && sudo chmod -R u+rwX,go-w '.$this->bundle)
        ->expectsOutputToContain('Then re-run as that user, or name it with --runtime-user. Whoever runs it must be able to write .env:')
        // After the chown, the re-run names the existing directory as is.
        // Listed first: each output line feeds the first expectation it matches.
        ->expectsOutputToContain(NymBundle::installCommand($this->bundle, 'www-data', existing: true))
        ->expectsOutputToContain('--runtime-user=www-data')
        ->assertExitCode(1);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
});

it('judges the bundle for --runtime-user, by name or uid, instead of the user running the installer', function (string $as) {
    // The installer runs as someone else (seam); the bundle is owned by the
    // runtime user, which --runtime-user names.
    $owner = (int) fileowner($this->bundle);
    $this->app->instance(NymBundle::class, new NymBundle(euid: $owner + 4242));
    $user = $as === 'name' ? gl_userName($owner) : (string) $owner;

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--runtime-user' => $user, '--no-interaction' => true])
        ->expectsOutputToContain('safety-net wired (nym)')
        ->expectsOutputToContain('the bundle checks ran for '.NymBundle::userLabel($owner).' (--runtime-user)')
        ->assertExitCode(0);

    expect(file_get_contents($this->env))->toBe(
        "APP_ENV=testing\nGAZE_SAFETY_NET=true\nGAZE_SAFETY_NET_BACKEND=nym\nGAZE_NYM_MODEL_DIR={$this->bundle}\n"
    );
})->with(['name', 'uid'])->skip(! function_exists('posix_getpwuid'), 'ext-posix not available');

it('refuses a bundle the --runtime-user does not own, with hints for that user', function () {
    $runtimeUid = (int) fileowner($this->bundle) + 4242;

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--runtime-user' => (string) $runtimeUid, '--no-interaction' => true])
        ->expectsOutputToContain(', checked as uid '.$runtimeUid)
        ->expectsOutputToContain('Fetch the pinned bundle as the user PHP-FPM and your queue workers run as:')
        ->expectsOutputToContain("sudo -u '#{$runtimeUid}' env XDG_DATA_HOME=/srv/gaze /fake/gaze setup --safety-net nym")
        ->expectsOutputToContain("sudo chown -R {$runtimeUid} {$this->bundle}")
        ->expectsOutputToContain("--runtime-user={$runtimeUid}")
        ->assertExitCode(1);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
});

it('rejects a --runtime-user that names no user, before touching .env', function () {
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--runtime-user' => 'no-such-gaze-user', '--no-interaction' => true])
        ->expectsOutputToContain('--runtime-user=no-such-gaze-user: no such user on this host.')
        ->assertExitCode(2);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
})->skip(! function_exists('posix_getpwnam'), 'ext-posix not available');

it('wires a bundle it cannot read when --runtime-user owns it, and defers the file checks to doctor', function () {
    // A 0700 directory of another user stands in for the www-data bundle the
    // deploy user cannot open.
    $dir = gl_requireForeignPrivateDir();
    $owner = (int) fileowner($dir);
    $name = gl_userName($owner);

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $dir, '--runtime-user' => $name, '--no-interaction' => true])
        ->expectsOutputToContain('safety-net wired (nym)')
        ->expectsOutputToContain('so only the directory itself was checked. Check its files as the runtime user: sudo -u '.$name.' php artisan gaze:doctor')
        ->assertExitCode(0);

    expect(file_get_contents($this->env))->toEndWith("GAZE_NYM_MODEL_DIR={$dir}\n");

    // Without --runtime-user the same directory is refused: the owner differs.
    file_put_contents($this->env, "APP_ENV=testing\n");
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $dir, '--no-interaction' => true])
        ->expectsOutputToContain('the directory is owned by '.NymBundle::userLabel($owner))
        ->assertExitCode(1);
    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
})->skip(fn () => gl_foreignPrivateDir() === null, 'needs a 0700 directory of another user, e.g. /root on Linux');

it('says the owner was not checked when ext-posix is missing', function () {
    $this->app->instance(NymBundle::class, new NymBundle(posix: false));

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--no-interaction' => true])
        ->expectsOutputToContain('owner not checked (ext-posix missing)')
        ->assertExitCode(0);
});

it('refuses non-interactively without any bundle dir and prints the gaze setup command', function () {
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--no-interaction' => true])
        ->expectsOutputToContain('the nym backend needs the bundle directory: pass --nym-model-dir, set GAZE_NYM_MODEL_DIR')
        ->expectsOutputToContain('sudo -u www-data env XDG_DATA_HOME=/srv/gaze /fake/gaze setup --safety-net nym --non-interactive --policy-out /srv/gaze/gaze-setup.toml --force')
        ->expectsOutputToContain('php artisan gaze:install:safety-net --safety-net=nym --nym-model-dir=/srv/gaze/gaze/models/nym-small-int8 --runtime-user=www-data')
        ->assertExitCode(1);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
});

it('derives XDG_DATA_HOME for the setup hint from a standard bundle path', function () {
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => '/var/lib/app/gaze/models/nym-small-int8', '--no-interaction' => true])
        ->expectsOutputToContain('sudo -u www-data env XDG_DATA_HOME=/var/lib/app /fake/gaze setup --safety-net nym')
        ->assertExitCode(1);
});

it('wires only the switch and selector when the policy already names a valid bundle', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-').'.toml';
    file_put_contents($policy, "[safety_net.nym]\nmodel_dir = \"{$this->bundle}\"\n");
    $this->app['config']->set('gaze.policy_path', $policy);

    try {
        $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--no-interaction' => true])
            ->assertExitCode(0);

        expect(file_get_contents($this->env))->toBe("APP_ENV=testing\nGAZE_SAFETY_NET=true\nGAZE_SAFETY_NET_BACKEND=nym\n");
    } finally {
        @unlink($policy);
    }
});

it('refuses a set-but-empty GAZE_NYM_MODEL_DIR instead of accepting the policy bundle behind it', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-').'.toml';
    file_put_contents($policy, "[safety_net.nym]\nmodel_dir = \"{$this->bundle}\"\n");
    $this->app['config']->set('gaze.policy_path', $policy);
    file_put_contents($this->env, "APP_ENV=testing\nGAZE_NYM_MODEL_DIR=\n");
    $_ENV['GAZE_NYM_MODEL_DIR'] = '';

    try {
        $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--no-interaction' => true])
            ->expectsOutputToContain(NymBundle::EMPTY_ENV.' .env was not changed.')
            ->expectsOutputToContain('Or pass --nym-model-dir=<bundle dir>')
            ->assertExitCode(1);

        expect(file_get_contents($this->env))->toBe("APP_ENV=testing\nGAZE_NYM_MODEL_DIR=\n");

        // --nym-model-dir fills the blank line in place.
        $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--no-interaction' => true])
            ->assertExitCode(0);

        expect(file_get_contents($this->env))->toBe(
            "APP_ENV=testing\nGAZE_NYM_MODEL_DIR={$this->bundle}\nGAZE_SAFETY_NET=true\nGAZE_SAFETY_NET_BACKEND=nym\n"
        );
    } finally {
        @unlink($policy);
    }
});

it('validates a bundle that the policy names but is broken, without writing', function () {
    $policy = tempnam(sys_get_temp_dir(), 'gaze-nym-policy-').'.toml';
    file_put_contents($policy, "[safety_net.nym]\nmodel_dir = \"{$this->bundle}\"\n");
    $this->app['config']->set('gaze.policy_path', $policy);
    chmod($this->bundle, 0750);

    try {
        $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--no-interaction' => true])
            ->expectsOutputToContain('(policy [safety_net.nym] model_dir) would be refused by gaze; .env was not changed:')
            ->assertExitCode(1);

        expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
    } finally {
        @unlink($policy);
    }
});

it('prompts for the bundle dir interactively and writes the answer', function () {
    $this->artisan('gaze:install:safety-net')
        ->expectsChoice('Which safety-net backend?', 'nym', [
            'nym' => 'Nym-small (compiled into the release binary)',
            'opf' => 'OpenAI privacy-filter (Tier 2, needs a safety-net-openai build)',
        ])
        ->expectsQuestion('Nym bundle directory (from `gaze setup --safety-net nym`)', $this->bundle)
        ->assertExitCode(0);

    expect(file_get_contents($this->env))->toContain("GAZE_NYM_MODEL_DIR={$this->bundle}\n");
});

it('validates before --print and prints the pairs without writing', function () {
    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--print' => true, '--no-interaction' => true])
        ->expectsOutputToContain("GAZE_SAFETY_NET=true\nGAZE_SAFETY_NET_BACKEND=nym\nGAZE_NYM_MODEL_DIR={$this->bundle}")
        ->assertExitCode(0);

    chmod($this->bundle, 0755);

    $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => $this->bundle, '--print' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('GAZE_SAFETY_NET=true')
        ->assertExitCode(1);

    expect(file_get_contents($this->env))->toBe("APP_ENV=testing\n");
});

it('writes a relative --nym-model-dir as an absolute path (gaze would resolve it against the worker cwd)', function () {
    $cwd = (string) getcwd();
    chdir(dirname($this->bundle));
    $expected = getcwd().'/'.basename($this->bundle);

    try {
        $this->artisan('gaze:install:safety-net', ['--safety-net' => 'nym', '--nym-model-dir' => basename($this->bundle), '--no-interaction' => true])
            ->assertExitCode(0);
    } finally {
        chdir($cwd);
    }

    expect(file_get_contents($this->env))->toEndWith("GAZE_NYM_MODEL_DIR={$expected}\n");
});
