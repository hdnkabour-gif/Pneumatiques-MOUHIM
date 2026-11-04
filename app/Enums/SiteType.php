<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SiteType: string implements HasLabel
{
    case Depot = 'depot';
    case Store = 'store';

    public function label(): string
    {
        return __('sites.types.'.$this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
