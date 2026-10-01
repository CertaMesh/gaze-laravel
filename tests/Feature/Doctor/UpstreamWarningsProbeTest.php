<?php

declare(strict_types=1);

use CertaMesh\Gaze\BinaryResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * #159: gaze >= 0.15 prints its policy warnings on stderr only when a clean
 * SUCCEEDS, and Gaze::clean() discards that stderr. gaze:doctor runs one real
 * clean on a fixed input and reports each `warning:` / `notice:` line. The
 * lines below are verbatim gaze 0.15.1 output (crates/gaze-cli/src/pipeline).
 */

const UWP_PROBE_INPUT = 'gaze doctor probe';

const UWP_PRESERVE = 'warning: policy preserves 2 detected classes without a reachable class rule: custom:iban, custom:url; values can reach the model raw. Back up custom rules, then run gaze setup --force, or set the default action to "tokenize".';

const UWP_GENERALIZE = 'warning: class generalize is one-way (no restore token) for: email.';

const UWP_CORE_FLOOR = 'notice: core rulepack floor is off';

const UWP_FAMILY = 'warning: policy names a member class of \'custom:family:payment-card-or-iban\' but no reachable rule names the family class itself; a span the family cannot settle (no anchor cue, or a precedence tie) is emitted as \'custom:family:payment-card-or-iban\' and takes the strictest action among its member classes\' rules and the default rule. To set it directly, add BEFORE your default rule: [[rule]] kind = "class" class = "custom:family:payment-card-or-iban" action = "tokenize"';

const UWP_CORE_EXTENDED = 'warning: `--rulepack-bundled core-extended` is deprecated since v0.8.0; use `--rulepack-bundled core --locale=<lang>` for explicit activation';

/**
 * Fake `gaze --version` and the probe's `gaze clean`. A successful clean
 * echoes the input as clean_text, as upstream does when nothing is detected.
 */
function uwp_fake(string $version, string $stderr = '', int $exitCode = 0): void
{
    Process::fake(function ($process) use ($version, $stderr, $exitCode) {
        $command = is_array($process->command) ? $process->command : [];
        if (! in_array('clean', $command, true)) {
            return Process::result(output: "gaze {$version}\n");
        }

        return $exitCode === 0
            ? Process::result(output: '{"clean_text":"'.UWP_PROBE_INPUT.'","session_blob":"YmxvYg=="}', errorOutput: $stderr)
            : Process::result(output: '', errorOutput: $stderr, exitCode: $exitCode);
    });
}

/**
 * A policy whose fall-through rule preserves: the static check's trigger.
 */
function uwp_preservePolicy(): string
{
    $path = tempnam(sys_get_temp_dir(), 'gaze-uwp-policy-').'.toml';
    file_put_contents($path, "[session]\nscope = \"persistent\"\nttl_secs = 86400\n\n[policy.rulepacks]\nbundled = [\"core\"]\n\n"
        ."[[rule]]\nkind = \"class\"\nclass = \"email\"\naction = \"tokenize\"\n\n[[rule]]\nkind = \"default\"\naction = \"preserve\"\n");

    return $path;
}

/**
 * Run gaze:doctor and return its exit code and full output, for counting.
 *
 * @return array{0:int,1:string}
 */
function uwp_doctor(): array
{
    $exit = Artisan::call('gaze:doctor');

    return [$exit, Artisan::output()];
}

beforeEach(function () {
    $this->app->instance(
        BinaryResolver::class,
        new BinaryResolver(explicitPath: '/fake/gaze', vendorBinPath: '/none'),
    );
    $this->app['config']->set('gaze.policy_path', __DIR__.'/../../../resources/policy.toml');
    $this->policies = [];
});

afterEach(function () {
    foreach ($this->policies as $policy) {
        @unlink($policy);
    }
});

it('reports every upstream warning and notice line as a WARN row and keeps exit 0', function () {
    uwp_fake('0.15.1', implode("\n", [
        UWP_FAMILY,
        UWP_GENERALIZE,
        UWP_CORE_FLOOR,
        // Not a `warning:` / `notice:` line: never echoed.
        '{"warning":"SafetyNet","variant":"ClassMismatch","count":1}',
        'some other upstream chatter',
    ])."\n");

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*3/')
        ->toContain(UWP_FAMILY)
        ->toContain(UWP_GENERALIZE)
        ->toContain(UWP_CORE_FLOOR)
        ->toContain('keep core in GAZE_RULEPACKS')
        ->not->toContain('"SafetyNet"')
        ->not->toContain('some other upstream chatter')
        // The probe input never reaches the console, though gaze echoes it
        // back as clean_text.
        ->not->toContain(UWP_PROBE_INPUT)
        ->toMatch('/status\s.*OK/');
});

it('runs one gaze clean of the fixed input with the configured pipeline flags but no --audit-db', function () {
    $this->app['config']->set('gaze.locale', 'de-DE');
    $this->app['config']->set('gaze.rulepacks', ['core', 'secrets']);
    $this->app['config']->set('gaze.audit_db_path', '/var/lib/gaze/audit.sqlite');
    uwp_fake('0.15.1');

    $this->artisan('gaze:doctor')->assertExitCode(0);

    Process::assertRanTimes(fn ($process): bool => in_array('clean', (array) $process->command, true), 1);
    Process::assertRan(function ($process): bool {
        $command = (array) $process->command;
        if (! in_array('clean', $command, true)) {
            return false;
        }

        expect(array_slice($command, 0, 4))->toBe([
            '/fake/gaze',
            'clean',
            '--policy='.__DIR__.'/../../../resources/policy.toml',
            '--format=json',
        ])
            ->and($command)->toContain('--locale=de-DE', '--rulepack-bundled=core', '--rulepack-bundled=secrets')
            ->and(implode(' ', $command))->not->toContain('--audit-db')
            ->and($process->input)->toBe(UWP_PROBE_INPUT);

        return true;
    });
});

it('reports none when gaze >= 0.15 prints no warning', function () {
    uwp_fake('0.15.1');

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*none/')
        ->not->toContain('static checks only')
        ->not->toContain('probe failed');
});

it('fails on a NonRetryable probe error, since every clean fails the same way, and still runs the static checks', function () {
    $this->policies[] = $policy = uwp_preservePolicy();
    $this->app['config']->set('gaze.policy_path', $policy);
    uwp_fake('0.15.1', '{"error":"PolicyConfig","exit":2}', 2);

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(1)
        ->and($output)->toMatch('/upstream warnings\s.*FAIL/')
        ->toContain('The gaze clean probe failed (gaze clean probe ')
        ->toContain('Every Gaze::clean() fails the same way')
        ->not->toContain('using the static policy checks only')
        // The static preserve check still reports what it can see.
        ->toContain('policy default')
        ->toContain('reaches the model raw')
        ->toMatch('/status\s.*FAIL/')
        ->not->toContain('encrypter');
});

it('reports a transient probe error as a WARN row, falls back to the static checks and keeps exit 0', function () {
    $this->policies[] = $policy = uwp_preservePolicy();
    $this->app['config']->set('gaze.policy_path', $policy);
    uwp_fake('0.15.1', '{"error":"SafetyNet","exit":3,"variant":"Timeout"}', 3);

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*probe failed/')
        ->toContain('The gaze clean probe failed (gaze clean probe ')
        ->toContain('using the static policy checks only')
        // The static preserve check stands in for the probe.
        ->toContain('policy default')
        ->toContain('reaches the model raw')
        ->toMatch('/status\s.*OK/');
});

it('times out cleanly as a WARN row and keeps exit 0', function () {
    Process::fake(function ($process) {
        if (in_array('clean', (array) $process->command, true)) {
            throw new SymfonyTimeoutException(new SymfonyProcess(['gaze']), SymfonyTimeoutException::TYPE_GENERAL);
        }

        return Process::result(output: "gaze 0.15.1\n");
    });

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(0)
        ->and($output)->toMatch('/upstream warnings\s.*probe failed/')
        ->toContain('gaze clean probe timed out')
        ->toMatch('/status\s.*OK/');
});

it('prints a finding once when upstream and a static check both cover it', function (string $version, string $stderr, ?array $rulepacks, bool $preservePolicy, array $once, array $absent) {
    if ($preservePolicy) {
        $this->policies[] = $policy = uwp_preservePolicy();
        $this->app['config']->set('gaze.policy_path', $policy);
    }
    $this->app['config']->set('gaze.rulepacks', $rulepacks);
    uwp_fake($version, $stderr);

    [$exit, $output] = uwp_doctor();

    expect($exit)->toBe(0);
    foreach ($once as $needle) {
        expect(substr_count($output, $needle))->toBe(1, "expected exactly one '{$needle}' in:\n{$output}");
    }
    foreach ($absent as $needle) {
        expect($output)->not->toContain($needle);
    }
})->with([
    // Upstream names the leaking classes; the static row would repeat it.
    'preserve: upstream line replaces the static row' => [
        '0.15.1', UWP_PRESERVE, null, true,
        [UWP_PRESERVE, 'Set the default rule to action = "tokenize"'],
        ['policy default'],
    ],
    // gaze >= 0.15 vouches for the policy: every class has a rule, so the
    // static check's preserve-default finding is a false positive.
    'preserve: a silent gaze >= 0.15 overrules the static check' => [
        '0.15.1', '', null, true,
        [],
        ['policy default', 'warning: policy preserves'],
    ],
    // A binary that cannot print the warning proves nothing by silence.
    'preserve: static fallback on gaze < 0.15' => [
        '0.12.0', '', null, true,
        ['policy default', 'Set the default rule to action = "tokenize"', 'static checks only'],
        ['warning: policy preserves'],
    ],
    // Unknown version (a custom build) but the line is there: no repeat.
    'preserve: upstream line wins on an unknown version too' => [
        'custom-build', UWP_PRESERVE, null, true,
        [UWP_PRESERVE, 'Set the default rule to action = "tokenize"'],
        ['policy default'],
    ],
    'core floor: upstream notice replaces the static row' => [
        '0.15.1', UWP_CORE_FLOOR, ['secrets'], false,
        [UWP_CORE_FLOOR, 'keep core in GAZE_RULEPACKS'],
        ['(no core)'],
    ],
    'core floor: static fallback on gaze < 0.15' => [
        '0.12.0', '', ['secrets'], false,
        ['(no core)', 'static checks only'],
        [UWP_CORE_FLOOR],
    ],
    // The static check also covers the policy file, so it stays, and gaze's
    // own deprecation line is the duplicate.
    'core-extended: static warning stays, upstream line dropped' => [
        '0.15.1', UWP_CORE_EXTENDED, ['core-extended'], false,
        ["rulepack 'core-extended' is deprecated", 'upstream warnings'],
        ['--rulepack-bundled core-extended', 'probe failed'],
    ],
]);
