<?php

declare(strict_types=1);

use CertaMesh\Gaze\Variant;

it('parses known stderr variants', function (Variant $expected) {
    $stderr = json_encode([
        'error' => $expected->value,
        'exit' => $expected->exitBucket(),
    ], JSON_THROW_ON_ERROR);

    expect(Variant::tryFromStderr($stderr, $expected->exitBucket()))->toBe($expected);
})->with([
    Variant::StdinParse,
    Variant::EmptyInput,
    Variant::InputTooLarge,
    Variant::InvalidEncoding,
    Variant::PolicyConfig,
    Variant::UnknownToken,
    Variant::InvalidSignature,
    Variant::InvalidBlobVersion,
    Variant::BlobExpired,
    Variant::Pipeline,
    Variant::Io,
    Variant::SigPipe,
    Variant::PolicyOpen,
]);

it('falls back to the exit bucket when stderr is malformed', function () {
    expect(Variant::tryFromStderr('not-json', 3))->toBe(Variant::UnknownToken);
});

it('uses the process exit as the tie-break when stderr exit diverges', function () {
    $stderr = json_encode([
        'error' => Variant::PolicyConfig->value,
        'exit' => 2,
    ], JSON_THROW_ON_ERROR);

    expect(Variant::tryFromStderr($stderr, 3))->toBe(Variant::UnknownToken);
});

it('parses non-empty stderr on exit 141 through the regular path', function () {
    $stderr = json_encode([
        'error' => Variant::Io->value,
        'exit' => 141,
    ], JSON_THROW_ON_ERROR);

    expect(Variant::tryFromStderr($stderr, 141))->toBe(Variant::Io);
});
