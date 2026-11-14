<?php

namespace App\Actions\Sales;

use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleDocuments;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Recap invoice (D-16): groups a professional customer's delivery / work
 * notes of a period. Stock and approvals were handled on each note; the
 * invoice carries their lines and their remaining credit.
 */
class CreateRecapInvoiceAction
{
    public function __construct(private SequenceService $sequences) {}

    /** @param  list<int>  $noteIds */
    public function execute(Customer $customer, array $noteIds, User $user): Sale
    {
        if (! $user->can(Permission::SalesCreate->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        return DB::transaction(function () use ($customer, $noteIds, $user) {
            $notes = Sale::query()->with('lines')->whereKey($noteIds)->lockForUpdate()->orderBy('confirmed_at')->orderBy('id')->get();

            if ($notes->isEmpty() || $notes->count() !== count(array_unique($noteIds)) || $notes->contains(fn (Sale $n) => ! $n->isKind(SaleKind::DeliveryNote)
                || $n->status !== SaleStatus::Confirmed || $n->customer_id !== $customer->id || $n->recap_invoice_id !== null
                || ! $user->canAccessSite($n->site_id))) {
                throw BusinessRuleException::make('sales.errors.recap_notes');
            }

            $invoice = Sale::query()->create([
                'kind' => SaleKind::Invoice,
                'status' => SaleStatus::Draft,
                'site_id' => $notes->first()->site_id,
                'customer_id' => $customer->id,
                'price_level' => $customer->price_level,
                'seller_id' => $user->id,
                'po_number' => $notes->pluck('po_number')->filter()->unique()->implode(', ') ?: null,
                'notes' => __('sales.recap.notes', ['numbers' => $notes->pluck('number')->implode(', ')], 'fr'),
                ...collect(['total_ht_cents', 'total_vat_cents', 'total_ttc_cents', 'discount_cents', 'paid_cents', 'credit_cents'])
                    ->mapWithKeys(fn ($c) => [$c => (int) $notes->sum($c)])->all(),
            ]);

            $order = 0;
            foreach ($notes as $note) {
                foreach ($note->lines as $line) {
                    $invoice->lines()->create([
                        ...collect($line->getAttributes())->except(['id', 'sale_id', 'original_line_id', 'restock', 'meta'])->all(),
                        'description' => $note->number.' – '.$line->description,
                        'sort_order' => $order++,
                    ]);
                }
            }

            $invoice->forceFill([
                'number' => $this->sequences->nextDocumentNumber(DocumentType::Invoice),
                'status' => SaleStatus::Confirmed,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            Sale::query()->whereKey($notes->modelKeys())->update(['recap_invoice_id' => $invoice->id]);

            activity('sales')->causedBy($user)->performedOn($invoice)->event('recap_invoice')
                ->withProperties(['number' => $invoice->number, 'notes' => $notes->pluck('number')->all()])
                ->tap(fn ($a) => $a->site_id = $invoice->site_id)->log('recap_invoice');

            app(SaleDocuments::class)->archiveInvoice($invoice);

            return $invoice->load('lines');
        });
    }
}
