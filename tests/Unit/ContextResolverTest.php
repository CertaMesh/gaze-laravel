<?php

declare(strict_types=1);

use Naoray\GazeLaravel\Context;
use Naoray\GazeLaravel\Contracts\ContextResolver;

it('lets an implementation return a Context from an app-specific source', function () {
    $resolver = new class implements ContextResolver
    {
        public function resolve(mixed $source): Context
        {
            return new Context(
                customerName: $source['name'] ?? null,
                customerEmail: $source['email'] ?? null,
            );
        }
    };

    $context = $resolver->resolve(['name' => 'Alice', 'email' => 'a@example.com']);

    expect($context->customerName)->toBe('Alice')
        ->and($context->customerEmail)->toBe('a@example.com')
        ->and($context->toArray())->toBe([
            'customer_name' => 'Alice',
            'customer_email' => 'a@example.com',
        ]);
});
