<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Actions\Purchases\PurchaseActions;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Fields;
use App\Models\PurchaseOrder;
use App\Support\Decimal;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['lines.product', 'supplier', 'site']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive')->label(__('purchases.actions.receive'))->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                ->visible(fn () => in_array($this->record->status, ['ordered', 'partial'], true))
                ->schema(fn () => [
                    TextInput::make('invoice_ref')->label(__('purchases.fields.invoice_ref')),
                    ...$this->record->lines->filter(fn ($l) => $l->remaining() > 0)->map(fn ($l) => Grid::make(['default' => 2, 'md' => 4])->schema([
                        Text::make($l->product->name)->columnSpan(['default' => 2, 'md' => 1]),
                        TextInput::make("lines.{$l->id}.quantity")->label(__('purchases.fields.quantity'))->integer()->minValue(0)->default($l->remaining()),
                        Fields::money("lines.{$l->id}.unit_cost_cents", __('purchases.fields.unit_cost'))->default(Decimal::fromCents($l->unit_cost_cents)),
                        TextInput::make("lines.{$l->id}.dot")->label(__('purchases.fields.dot'))->maxLength(4),
                    ]))->values()->all(),
                ])
                ->action(function (array $data) {
                    /** @var PurchaseOrder $order */
                    $order = $this->record;
                    $lines = [];
                    foreach ($data['lines'] ?? [] as $id => $l) {
                        $line = $order->lines->firstWhere('id', (int) $id);
                        $lines[] = ['order_line_id' => (int) $id, 'product_id' => $line->product_id, 'quantity' => (int) ($l['quantity'] ?? 0),
                            'unit_cost_cents' => (int) ($l['unit_cost_cents'] ?? $line->unit_cost_cents), ...CreatePurchaseOrder::dot($l['dot'] ?? null)];
                    }
                    try {
                        app(PurchaseActions::class)->receive($order->supplier, $order->site, $lines, auth()->user(), $data['invoice_ref'] ?? null, $order);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('cash.saved'))->send();
                    $this->record = $this->resolveRecord($order->id);
                }),
        ];
    }
}
