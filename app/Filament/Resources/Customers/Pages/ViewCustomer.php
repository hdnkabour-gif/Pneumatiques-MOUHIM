<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Actions\Money\CustomerAccountActions;
use App\Actions\Sales\CreateRecapInvoiceAction;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\SaleScreen;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Site;
use App\Support\Decimal;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

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
            Action::make('receive')->label(__('money.receive'))->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn () => auth()->user()->can(Permission::PaymentsReceive->value) && $this->record->outstandingCents() > 0)
                ->modalDescription(fn () => __('customers.fields.outstanding').' : '.Money::format($this->record->outstandingCents()).'. '.__('money.receive_help'))
                ->schema([
                    Select::make('method')->label(__('money.method'))->required()->default('cash')->live()
                        ->options(collect([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Cheque, PaymentMethod::Transfer])
                            ->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all()),
                    Fields::money('amount_cents', __('money.amount'))->required()
                        ->default(fn () => Decimal::fromCents($this->record->outstandingCents())),
                    TextInput::make('cheque_number')->label(__('sales.fields.cheque_number'))->required()
                        ->visible(fn (Get $get) => $get('method') === 'cheque'),
                    TextInput::make('bank')->label(__('sales.fields.bank'))->required()
                        ->visible(fn (Get $get) => $get('method') === 'cheque'),
                    DatePicker::make('due_date')->label(__('sales.fields.due_date'))
                        ->visible(fn (Get $get) => $get('method') === 'cheque'),
                    // #21: one key per opened form. A double click or a network retry of the
                    // same form posts once; reopening the form for a new receipt gets a new key.
                    Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                ])
                ->action(function (array $data) {
                    $site = Site::query()->find(auth()->user()->default_site_id) ?? Site::query()->visibleTo(auth()->user())->firstOrFail();
                    try {
                        app(CustomerAccountActions::class)->receive($this->record, $site, $data, auth()->user());
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('money.done'))->send();
                }),
            Action::make('opening')->label(__('money.opening'))->icon(Heroicon::OutlinedBookOpen)->color('gray')
                ->visible(fn () => auth()->user()->can(Permission::ApprovalsDecide->value))
                ->modalDescription(__('money.opening_help'))
                ->schema([
                    Fields::money('amount_cents', __('money.amount'))->required(),
                    DatePicker::make('as_of')->label(__('money.as_of'))->required()->default(today()),
                    TextInput::make('note')->label(__('money.note')),
                ])
                ->action(fn (array $data) => app(CustomerAccountActions::class)
                    ->addOpeningBalance($this->record, (int) $data['amount_cents'], $data['as_of'], $data['note'] ?? null, auth()->user())),
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
