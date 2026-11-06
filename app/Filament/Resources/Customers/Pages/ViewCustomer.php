<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Sales\CreateRecapInvoiceAction;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recap')->label(__('sales.recap.action'))->icon(Heroicon::OutlinedDocumentDuplicate)->color('success')
                ->visible(fn () => SaleScreen::canAccess() && $this->unbilledNotes()->isNotEmpty())
                ->schema(fn () => [
                    CheckboxList::make('notes')->label(__('sales.recap.pick'))->required()
                        ->options($this->unbilledNotes()->mapWithKeys(fn (Sale $n) => [$n->id => $n->number.' – '.$n->confirmed_at->format('d/m/Y').' – '.Money::format($n->total_ttc_cents)])->all())
                        ->default($this->unbilledNotes()->modelKeys())->bulkToggleable(),
                ])
                ->action(function (array $data) {
                    try {
                        $invoice = app(CreateRecapInvoiceAction::class)->execute($this->record, array_map('intval', $data['notes']), auth()->user());
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $this->redirect(SaleResource::getUrl('view', ['record' => $invoice]));
                }),
            Action::make('sell')->label(__('sales.screen.title'))->icon(Heroicon::OutlinedShoppingCart)
                ->visible(fn () => SaleScreen::canAccess())
                ->url(fn () => SaleScreen::getUrl(['customer' => $this->record->id])),
            EditAction::make(),
        ];
    }

    private function unbilledNotes()
    {
        /** @var Customer $customer */
        $customer = $this->record;

        return $customer->sales()->where('kind', SaleKind::DeliveryNote->value)->where('status', SaleStatus::Confirmed->value)
            ->whereNull('recap_invoice_id')->visibleTo(auth()->user())->orderBy('confirmed_at')->get();
    }
}
