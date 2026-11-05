<?php

namespace App\Filament\Resources\UsedTyres\Pages;

use App\Filament\Resources\UsedTyres\UsedTyreResource;
use Filament\Resources\Pages\EditRecord;

/** Descriptive fields only: status and site change through actions (inspection, scrap, sale). */
class EditUsedTyre extends EditRecord
{
    protected static string $resource = UsedTyreResource::class;
}
