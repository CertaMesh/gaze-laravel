<?php

declare(strict_types=1);

it('publishes a v0.4 multi-class policy with bundled rulepacks', function () {
    $sourcePath = dirname(__DIR__, 2).'/policy.toml.example';
    expect($sourcePath)->toBeFile();

    $body = file_get_contents($sourcePath);
    expect($body)->toBeString()->not->toBe('');

    // Schema surface: v0.4 - retired [[detector]] MUST NOT appear.
    expect($body)->not->toMatch('/^\s*\[\[detector\]\]/m');

    // v0.4 surface present, >=4 custom recognizers (invoice, money, street, org).
    $customRecognizers = preg_match_all('/^\s*\[\[policy\.custom_recognizers\]\]/m', $body);
    expect($customRecognizers)->toBeGreaterThanOrEqual(4);

    // Bundled rulepacks activated - both core and core-extended.
    expect($body)
        ->toMatch('/^\s*\[policy\.rulepacks\]/m')
        ->toContain('"core"')
        ->toContain('"core-extended"');

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
