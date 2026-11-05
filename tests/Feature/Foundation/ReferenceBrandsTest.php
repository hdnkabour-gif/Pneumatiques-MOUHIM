<?php

use App\Models\Brand;
use Database\Seeders\ReferenceBrandsSeeder;

it('loads the 57 tyre and oil brands once, without the non-lubricant entry', function () {
    expect(Brand::query()->count())->toBe(57)
        ->and(Brand::query()->where('name', 'Michelin')->exists())->toBeTrue()
        ->and(Brand::query()->where('name', 'like', '%Lesieur%')->exists())->toBeFalse();

    $this->seed(ReferenceBrandsSeeder::class);
    expect(Brand::query()->count())->toBe(57);
});

it('keeps a brand the owner deactivated or wrote differently', function () {
    Brand::query()->where('name', 'Sava')->update(['is_active' => false]);
    Brand::query()->where('name', 'Elf')->update(['name' => 'ELF']);

    $this->seed(ReferenceBrandsSeeder::class);

    expect(Brand::query()->where('name', 'Sava')->value('is_active'))->toBeFalse()
        ->and(Brand::query()->whereRaw("lower(name) = 'elf'")->count())->toBe(1);
});
