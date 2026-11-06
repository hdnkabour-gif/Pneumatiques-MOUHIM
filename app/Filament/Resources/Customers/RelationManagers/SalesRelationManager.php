<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\SaleStatus;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Fields;
use App\Models\Sale;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('sales.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('status', '!=', SaleStatus::Draft->value)->visibleTo(auth()->user())->with('site'))
            ->defaultSort('confirmed_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('sales.fields.number'))->weight('bold'),
                TextColumn::make('confirmed_at')->label(__('sales.fields.date'))->dateTime('d/m/Y H:i'),
                TextColumn::make('site.code')->label(__('sales.fields.site'))->badge(),
                Fields::moneyColumn('total_ttc_cents', __('sales.fields.total_ttc')),
                Fields::moneyColumn('credit_cents', __('sales.fields.credit'))->color(fn ($state) => $state > 0 ? 'danger' : null),
            ])
            ->recordActions([
                Action::make('open')->label(__('sales.actions.open'))->url(fn (Sale $record) => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
