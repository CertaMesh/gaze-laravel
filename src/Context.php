<?php

declare(strict_types=1);

namespace Naoray\GazeLaravel;

final readonly class Context
{
    public function __construct(
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'customer_name' => $this->customerName,
                'customer_email' => $this->customerEmail,
                'customer_phone' => $this->customerPhone,
            ],
            static fn (?string $v): bool => $v !== null,
        );
    }
}
