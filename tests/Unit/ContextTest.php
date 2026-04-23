<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel\Tests\Unit;

use Naoray\GazeLaravel\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function test_empty_context_serializes_to_empty_array(): void
    {
        self::assertSame([], (new Context())->toArray());
    }

    public function test_it_emits_snake_case_keys(): void
    {
        $context = new Context(
            customerName: 'Krishan Koenig',
            customerEmail: 'k@example.com',
            customerPhone: '+353 1 234 5678',
        );

        self::assertSame(
            [
                'customer_name' => 'Krishan Koenig',
                'customer_email' => 'k@example.com',
                'customer_phone' => '+353 1 234 5678',
            ],
            $context->toArray(),
        );
    }

    public function test_null_values_are_stripped(): void
    {
        $context = new Context(customerEmail: 'k@example.com');

        self::assertSame(['customer_email' => 'k@example.com'], $context->toArray());
    }

    public function test_properties_are_readonly(): void
    {
        $context = new Context(customerName: 'Krishan');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line — intentional mutation test */
        $context->customerName = 'Mallory';
    }
}
