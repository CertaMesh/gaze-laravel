<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Tests\Fixtures\UpstreamErrorNames;
use CertaMesh\Gaze\Variant;
use Symfony\Component\Process\Process;

/*
 * Opt-in upstream drift check for the error contract (#184). Run it during a
 * pin bump (the lockstep audit in AGENTS.md), not in regular CI:
 *
 *   GAZE_UPSTREAM_SRC=/path/to/gaze vendor/bin/pest tests/Contract/UpstreamErrorDriftTest.php
 *
 * It reads upstream sources at tag `v{BinaryDownloader::PINNED_VERSION}` with
 * `git show` (the checkout's working tree is never touched), extracts every
 * error name the binary writes, and fails on a name that is neither mapped by
 * the PHP enum nor listed in UpstreamErrorNames. It also fails on an unmapped
 * entry upstream no longer writes, so those lists cannot go stale.
 *
 * Extraction is regex over Rust source, not a parser. What it reads:
 *   - error.rs: the `=> "Name"` arms of `fn variant_name` → `Variant`.
 *   - daemon.rs: `DaemonResponse::error(_, "Name", …)` literals, the
 *     `=> "Name"` arms of `fn variant` (`DaemonError::variant()`), and
 *     `"error":"Name"` literals in hand-written stderr JSON →
 *     `DaemonErrorVariant`.
 *   - daemon.rs + pipeline/run.rs: `SafetyNetFailure { variant: "Name" }`,
 *     which the daemon writes as `error` verbatim → `DaemonErrorVariant`.
 * Limits:
 *   - A name built at runtime (format!, a const, a helper returning &str) is
 *     invisible.
 *   - Function bodies are found by brace matching; a `{` or `}` inside a
 *     string literal in `variant_name` / `variant` would cut the body short.
 *   - Everything from the first `#[cfg(test)] mod` on is dropped, on the Rust
 *     convention that test modules sit at the end of the file.
 *   - A SafetyNetFailure built in a file other than these three is not read;
 *     the tripwire below fails instead, so the file list gets extended.
 * Every structural rule must match at least once, so a refactor upstream fails
 * loudly instead of passing on an empty list.
 */

function ued_upstreamSrc(): ?string
{
    $src = env('GAZE_UPSTREAM_SRC');

    return is_string($src) && $src !== '' ? $src : null;
}

/** @param  list<string>  $command */
function ued_git(string $src, array $command): Process
{
    $process = new Process(['git', '-C', $src, ...$command]);
    $process->run();

    return $process;
}

/** Production source of one file at the tag (trailing test modules dropped). */
function ued_show(string $src, string $tag, string $path): string
{
    $process = ued_git($src, ['show', "{$tag}:{$path}"]);

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            "git show {$tag}:{$path} failed in GAZE_UPSTREAM_SRC={$src}: ".trim($process->getErrorOutput()).
            " Point GAZE_UPSTREAM_SRC at a gaze checkout with tag {$tag} fetched; if upstream moved the file, update this test."
        );
    }

    $parts = preg_split('/^#\[cfg\(test\)\]\s*mod\s/m', $process->getOutput(), 2);

    return is_array($parts) ? $parts[0] : $process->getOutput();
}

/** Body of `fn $name(…)`, found by brace matching; '' when absent. */
function ued_fnBody(string $rust, string $name): string
{
    if (preg_match('/\bfn\s+'.preg_quote($name, '/').'\s*\(/', $rust, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }

    $open = strpos($rust, '{', $match[0][1]);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    for ($i = $open, $length = strlen($rust); $i < $length; $i++) {
        if ($rust[$i] === '{') {
            $depth++;
        } elseif ($rust[$i] === '}' && --$depth === 0) {
            return substr($rust, $open, $i - $open + 1);
        }
    }

    return '';
}

/** @return list<string> the unique first capture group of every match */
function ued_names(string $pattern, string $source): array
{
    preg_match_all($pattern, $source, $matches);

    return array_values(array_unique($matches[1]));
}

it('maps or deliberately lists every error name upstream writes at the pinned tag', function () {
    $src = ued_upstreamSrc();
    if ($src === null) {
        $this->markTestSkipped('GAZE_UPSTREAM_SRC not set; upstream error-name drift check skipped (run it during a pin bump, see docs/how-to/testing.md).');

        return;
    }

    $tag = 'v'.BinaryDownloader::PINNED_VERSION;
    $files = [
        'error' => 'crates/gaze-cli/src/error.rs',
        'daemon' => 'crates/gaze-cli/src/commands/daemon.rs',
        'run' => 'crates/gaze-cli/src/pipeline/run.rs',
    ];
    $errorRs = ued_show($src, $tag, $files['error']);
    $daemonRs = ued_show($src, $tag, $files['daemon']);
    $runRs = ued_show($src, $tag, $files['run']);

    $arm = '/=>\s*"(\w+)"/';
    $cliNames = ued_names($arm, ued_fnBody($errorRs, 'variant_name'));
    $daemonRules = [
        'DaemonResponse::error() names in daemon.rs' => ued_names('/DaemonResponse::error\(\s*[^,]*,\s*"(\w+)"/', $daemonRs),
        'DaemonError::variant() arms in daemon.rs' => ued_names($arm, ued_fnBody($daemonRs, 'variant')),
        'SafetyNetFailure { variant } in daemon.rs / run.rs' => ued_names('/SafetyNetFailure\s*\{\s*variant:\s*"(\w+)"/', $daemonRs."\n".$runRs),
    ];
    foreach (['variant_name() arms in error.rs' => $cliNames, ...$daemonRules] as $rule => $names) {
        expect($names)->not->toBeEmpty("{$rule}: extraction matched nothing at {$tag}; upstream refactored it, update this test");
    }

    // Tripwire for the file-list limit: a SafetyNetFailure elsewhere would be missed.
    $grep = ued_git($src, ['grep', '-l', 'SafetyNetFailure', $tag, '--', 'crates/gaze-cli/src']);
    $mentioning = array_map(
        fn (string $line): string => substr($line, strlen($tag) + 1),
        array_filter(explode("\n", trim($grep->getOutput()))),
    );
    expect(array_values(array_diff($mentioning, $files)))
        ->toBe([], "SafetyNetFailure appears in files this test does not read at {$tag}; add them to \$files");

    // Hand-written stderr JSON (`"error":"AuditWriteFailed"`) is optional, so no floor.
    $daemonNames = array_values(array_unique(array_merge(
        ued_names('/"error":"(\w+)"/', $daemonRs),
        ...array_values($daemonRules),
    )));

    $drift = [];
    foreach ($cliNames as $name) {
        if (Variant::tryFrom($name) === null && ! in_array($name, [...UpstreamErrorNames::UNMAPPED_VARIANTS, ...UpstreamErrorNames::RETIRED_VARIANTS], true)) {
            $drift[] = "error.rs writes {$name}: add a Variant case and a VariantContractTest row, or list it in UpstreamErrorNames::UNMAPPED_VARIANTS";
        }
    }
    foreach (array_diff(UpstreamErrorNames::UNMAPPED_VARIANTS, $cliNames) as $name) {
        $drift[] = "error.rs no longer writes {$name}: drop it from UpstreamErrorNames::UNMAPPED_VARIANTS";
    }
    foreach ($daemonNames as $name) {
        if (DaemonErrorVariant::fromWire($name) === DaemonErrorVariant::Unknown && ! in_array($name, UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS, true)) {
            $drift[] = "the daemon writes {$name}: add a DaemonErrorVariant case and a DaemonErrorVariantContractTest row, or list it in UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS";
        }
    }
    foreach (array_diff(UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS, $daemonNames) as $name) {
        $drift[] = "the daemon no longer writes {$name}: drop it from UpstreamErrorNames::UNMAPPED_DAEMON_ERRORS";
    }

    expect($drift)->toBe([], "gaze {$tag} drifted from the adapter's error contract:\n  ".implode("\n  ", $drift));
});
