<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Context;

it('serializes empty context to empty array', function () {
    expect((new Context())->toArray())->toBe([]);
});

it('emits snake_case keys', function () {
    $context = new Context(
        customerName: 'Krishan Koenig',
        customerEmail: 'k@example.com',
        customerPhone: '+353 1 234 5678',
    );

    expect($context->toArray())->toBe([
        'customer_name' => 'Krishan Koenig',
        'customer_email' => 'k@example.com',
        'customer_phone' => '+353 1 234 5678',
    ]);
});

it('strips null values', function () {
    expect((new Context(customerEmail: 'k@example.com'))->toArray())
        ->toBe(['customer_email' => 'k@example.com']);
});

it('has readonly properties', function () {
    $context = new Context(customerName: 'Krishan');

    /** @phpstan-ignore-next-line — intentional mutation test */
    $context->customerName = 'Mallory';
})->throws(\Error::class);
