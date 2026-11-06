<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /** One page, one tab per document type (simplicity: no separate menus). */
    public function getTabs(): array
    {
        $tab = fn (string $key, \Closure $scope) => Tab::make(__('sales.tabs.'.$key))->modifyQueryUsing($scope);

        return [
            'invoice' => $tab('invoice', fn (Builder $query) => $query->where('kind', SaleKind::Invoice->value)->where('status', '!=', SaleStatus::Draft->value)),
            'quote' => $tab('quote', fn (Builder $query) => $query->where('kind', SaleKind::Quote->value)->where('status', '!=', SaleStatus::Draft->value)),
            'delivery_note' => $tab('delivery_note', fn (Builder $query) => $query->where('kind', SaleKind::DeliveryNote->value)->where('status', '!=', SaleStatus::Draft->value)),
            'credit_note' => $tab('credit_note', fn (Builder $query) => $query->where('kind', SaleKind::CreditNote->value)),
            'draft' => $tab('draft', fn (Builder $query) => $query->where('status', SaleStatus::Draft->value)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label(__('sales.screen.title'))->icon(Heroicon::OutlinedShoppingCart)
                ->visible(fn () => SaleScreen::canAccess())->url(fn () => SaleScreen::getUrl()),
        ];
    }
}
