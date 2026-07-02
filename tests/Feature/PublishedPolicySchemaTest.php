<?php

declare(strict_types=1);

it('publishes a v0.4 multi-class policy with bundled rulepacks', function () {
    $sourcePath = dirname(__DIR__, 2).'/resources/policy.toml';
    expect($sourcePath)->toBeFile();

    $body = file_get_contents($sourcePath);
    if (! is_string($body)) {
        throw new RuntimeException('could not read resources/policy.toml');
    }

    expect($body)->toBeString()->not->toBe('');

    // Schema surface: v0.4 - retired [[detector]] MUST NOT appear.
    expect($body)->not->toMatch('/^\s*\[\[detector\]\]/m');

    // v0.4 surface present, >=4 custom recognizers (invoice, money, street, org).
    $customRecognizers = preg_match_all('/^\s*\[\[policy\.custom_recognizers\]\]/m', $body);
    expect($customRecognizers)->toBeGreaterThanOrEqual(4);

    // Bundled rulepacks activated - unified `core` only (v0.8 unification).
    expect($body)
        ->toMatch('/^\s*\[policy\.rulepacks\]/m')
        ->toContain('"core"');

    // Regression: 'core-extended' was unified into 'core' upstream in v0.8.0.
    // A future revert that re-introduces the deprecated bundle name must not
    // ship silently — the doctor warning + this assertion are the two gates.
    expect($body)->not->toContain('"core-extended"');

    // BCP47 locales - postal.de needs de-DE, postal.us needs en-US.
    expect($body)
        ->toMatch('/^\s*\[locale\]/m')
        ->toContain('"de-DE"')
        ->toContain('"en-US"');

    // Plain "de" / "en" non-BCP47 forms MUST NOT be present in [locale].active.
    expect($body)->not->toMatch('/active\s*=\s*\[\s*"(?:de|en)"/');

    // [ner] block must be commented (no live model_dir = no boot trap).
    expect($body)->not->toMatch('/^\s*\[ner\]/m');

    // Session: persistent + 24h TTL (NOT reference's 60s demo TTL).
    expect($body)
        ->toContain('scope = "persistent"')
        ->toContain('ttl_secs = 86400');

    // Default rule must preserve.
    expect($body)->toMatch('/kind\s*=\s*"default"\s*\n\s*action\s*=\s*"preserve"/');
});
