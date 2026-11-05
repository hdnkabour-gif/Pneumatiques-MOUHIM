<?php

namespace App\Filament\Actions;

use App\Actions\Stock\ReceiveStockAction;
use App\Actions\Stock\RequestStockAdjustmentAction;
use App\Enums\ApprovalStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

/** Stock actions available on a product (receipt, adjustment request). */
final class StockActions
{
    public static function receive(): Action
    {
        return Action::make('receiveStock')
            ->label(__('stock.actions.receive'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Product $record) => $record->isStocked() && auth()->user()->can(Permission::StockReceive->value))
            ->schema(fn (Product $record) => [
                Grid::make(2)->schema([
                    self::siteSelect()->default(Site::query()->where('code', 'DEP')->value('id')),
                    TextInput::make('quantity')->label(__('stock.fields.quantity'))->integer()->minValue(1)->required(),
                    Fields::money('unit_cost_cents', __('stock.fields.unit_cost'))->visible(fn () => Fields::canSeeCosts()),
                    TextInput::make('supplier')->label(__('stock.fields.supplier'))->maxLength(255),
                    TextInput::make('dot_week')->label(__('stock.fields.dot_week'))->integer()->minValue(1)->maxValue(53)->visible($record->isNewTyre()),
                    TextInput::make('dot_year')->label(__('stock.fields.dot_year'))->integer()->minValue(2000)->maxValue((int) date('Y'))->visible($record->isNewTyre()),
                    Toggle::make('opening')->label(__('stock.fields.opening')),
                ]),
            ])
            ->action(function (Product $record, array $data) {
                try {
                    app(ReceiveStockAction::class)->execute(
                        $record, Site::query()->findOrFail($data['site_id']), (int) $data['quantity'], $data['unit_cost_cents'] ?? null,
                        auth()->user(), (bool) ($data['opening'] ?? false), isset($data['dot_week']) ? (int) $data['dot_week'] : null,
                        isset($data['dot_year']) ? (int) $data['dot_year'] : null, $data['supplier'] ?? null,
                    );
                    Notification::make()->success()->title(__('stock.messages.received'))->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function adjust(): Action
    {
        return Action::make('adjustStock')
            ->label(__('stock.actions.adjust'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn (Product $record) => $record->isStocked() && auth()->user()->can(Permission::StockAdjust->value))
            ->schema([
                Grid::make(2)->schema([
                    self::siteSelect(),
                    TextInput::make('delta')->label(__('stock.fields.delta'))->integer()->required()->notIn([0]),
                    Toggle::make('damaged')->label(__('stock.fields.damaged')),
                    Textarea::make('reason')->label(__('stock.fields.reason'))->required()->columnSpanFull(),
                ]),
            ])
            ->action(function (Product $record, array $data) {
                try {
                    $request = app(RequestStockAdjustmentAction::class)->execute(
                        $record, Site::query()->findOrFail($data['site_id']), (int) $data['delta'], $data['reason'], auth()->user(), (bool) ($data['damaged'] ?? false),
                    );
                    Notification::make()->success()->title(__($request->status === ApprovalStatus::Approved ? 'stock.messages.adjustment_done' : 'stock.messages.adjustment_requested'))->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function siteSelect(string $name = 'site_id'): Select
    {
        return Select::make($name)->label(__('stock.fields.site'))
            ->options(fn () => Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->pluck('name', 'id'))
            ->default(fn () => auth()->user()->default_site_id)
            ->required();
    }
}
