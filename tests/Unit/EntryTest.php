<?php

declare(strict_types=1);

use CertaMesh\Gaze\Entry;

it('constructs from required upstream fields with nullable family', function () {
    $entry = new Entry(
        class: 'Email',
        raw: 'alice@example.com',
        token: 'Email_1',
    );

    expect($entry->class)->toBe('Email')
        ->and($entry->raw)->toBe('alice@example.com')
        ->and($entry->token)->toBe('Email_1')
        ->and($entry->family)->toBeNull();
});

it('preserves the family value when provided', function () {
    $entry = new Entry(
        class: 'PersonName',
        raw: 'Alice',
        token: 'PersonName_1',
        family: 'counter',
    );

    expect($entry->family)->toBe('counter');
});

it('builds from a decoded JSON array', function () {
    $entry = Entry::fromArray([
        'class' => 'Email',
        'raw' => 'alice@example.com',
        'token' => 'Email_1',
        'family' => 'counter',
    ]);

    expect($entry)->toBeInstanceOf(Entry::class)
        ->and($entry->class)->toBe('Email')
        ->and($entry->raw)->toBe('alice@example.com')
        ->and($entry->token)->toBe('Email_1')
        ->and($entry->family)->toBe('counter');
});

it('defaults missing optional family to null', function () {
    $entry = Entry::fromArray([
        'class' => 'Phone',
        'raw' => '+1-555-0100',
        'token' => 'Phone_1',
    ]);

    expect($entry->family)->toBeNull();
});

it('ignores unknown JSON fields for forward compatibility', function () {
    $entry = Entry::fromArray([
        'class' => 'Email',
        'raw' => 'a@b.com',
        'token' => 'Email_1',
        'family' => 'counter',
        'future_field' => 'should-not-blow-up',
        'another_unknown' => ['nested' => true],
    ]);

    expect($entry->class)->toBe('Email');
});

it('coerces missing required fields to empty strings rather than throwing', function () {
    $entry = Entry::fromArray([]);

    expect($entry->class)->toBe('')
        ->and($entry->raw)->toBe('')
        ->and($entry->token)->toBe('')
        ->and($entry->family)->toBeNull();
});
