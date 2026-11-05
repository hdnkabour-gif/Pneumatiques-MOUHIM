<?php

namespace App\Enums;

use App\Enums\Concerns\TranslatableEnum;
use Filament\Support\Contracts\HasLabel;

enum CategoryType: string implements HasLabel
{
    use TranslatableEnum;

    public const TRANSLATION_KEY = 'category_type';

    case NewTyre = 'new_tyre';
    case UsedTyre = 'used_tyre';
    case Rim = 'rim';
    case TyreSupply = 'tyre_supply';
    case Oil = 'oil';
    case Fluid = 'fluid';
    case Filter = 'filter';
    case Battery = 'battery';
    case Lighting = 'lighting';
    case Wiper = 'wiper';
    case Brake = 'brake';
    case Accessory = 'accessory';
    case Service = 'service';
    case Other = 'other';

    /** Name of the Product relation holding this category's technical fields. */
    public function specRelation(): ?string
    {
        return match ($this) {
            self::NewTyre => 'tyreSpec',
            self::Oil => 'oilSpec',
            self::Battery => 'batterySpec',
            self::Filter => 'filterSpec',
            self::Lighting => 'lightingSpec',
            default => null,
        };
    }

    public function productKind(): ProductKind
    {
        return $this === self::Service ? ProductKind::Service : ProductKind::Goods;
    }
}
