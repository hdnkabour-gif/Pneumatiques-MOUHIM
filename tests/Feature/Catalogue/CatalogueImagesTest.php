<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\Storage;

it('stores optional images for product brands and vehicle references without creating stock', function () {
    Storage::fake('public');
    Storage::disk('public')->put('catalog/brand-logos/michelin.webp', 'fake-image');
    Storage::disk('public')->put('catalog/vehicle-makes/dacia.webp', 'fake-image');
    Storage::disk('public')->put('catalog/vehicle-models/logan.webp', 'fake-image');
    Storage::disk('public')->put('catalog/products/tyre.webp', 'fake-image');

    $brand = Brand::query()->create([
        'name' => 'Michelin image test',
        'logo_path' => 'catalog/brand-logos/michelin.webp',
        'is_active' => true,
    ]);
    $make = VehicleMake::query()->create([
        'name' => 'Dacia image test',
        'logo_path' => 'catalog/vehicle-makes/dacia.webp',
        'is_active' => true,
    ]);
    $model = VehicleModel::query()->create([
        'make_id' => $make->id,
        'name' => 'Logan image test',
        'image_path' => 'catalog/vehicle-models/logan.webp',
        'is_active' => true,
    ]);
    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'image_path' => 'catalog/products/tyre.webp',
    ]);

    expect($brand->fresh()->logo_path)->toBe('catalog/brand-logos/michelin.webp')
        ->and($make->fresh()->logo_path)->toBe('catalog/vehicle-makes/dacia.webp')
        ->and($model->fresh()->image_path)->toBe('catalog/vehicle-models/logan.webp')
        ->and($product->fresh()->image_path)->toBe('catalog/products/tyre.webp')
        ->and($product->stockLevels()->count())->toBe(0);
});
