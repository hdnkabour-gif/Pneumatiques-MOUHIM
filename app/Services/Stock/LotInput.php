<?php

namespace App\Services\Stock;

/** DOT and origin of new tyres entering a site (§5.3). */
final readonly class LotInput
{
    public function __construct(
        public ?int $dotWeek = null,
        public ?int $dotYear = null,
        public ?string $supplier = null,
        public ?int $sourceLotId = null,
        public ?int $unitCostCents = null,
    ) {}
}
