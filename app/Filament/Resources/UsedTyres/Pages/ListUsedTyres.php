<?php

namespace App\Filament\Resources\UsedTyres\Pages;

use App\Filament\Resources\UsedTyres\UsedTyreResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsedTyres extends ListRecords
{
    protected static string $resource = UsedTyreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sets')->label(__('used_tyres.sections.sets'))->icon(Heroicon::OutlinedSquares2x2)->color('gray')
                ->url(UsedTyreResource::getUrl('sets')),
            CreateAction::make(),
        ];
    }
}
