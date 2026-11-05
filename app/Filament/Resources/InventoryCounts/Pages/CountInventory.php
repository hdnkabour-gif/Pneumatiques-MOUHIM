<?php

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Actions\Inventory\InventoryCountActions;
use App\Enums\InventoryCountStatus;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Services\Catalog\ProductSearch;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counting screen: one editable quantity per product, saved as soon as it is
 * typed. Expected quantities and differences are shown only to the validator
 * (blind count for the people counting).
 */
class CountInventory extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = InventoryCountResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()->can('view', $this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('inventory.label').' '.$this->getRecord()->number.' – '.$this->getRecord()->site->name;
    }

    private function count(): InventoryCount
    {
        /** @var InventoryCount */
        return $this->getRecord();
    }

    private function canValidate(): bool
    {
        return auth()->user()->can(Permission::InventoryApprove->value);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $editable = $this->count()->status === InventoryCountStatus::Draft;

        return $table
            ->splitSearchTerms(false)
            ->query(fn () => InventoryCountLine::query()->where('inventory_count_id', $this->count()->id)->with('product.tyreSpec'))
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('product.name')->label(__('inventory.fields.product'))->weight('bold')->wrap()
                    ->description(fn (InventoryCountLine $r) => trim($r->product->sku.' '.($r->product->tyreSpec?->sizeLabel() ?? '')))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('product', fn ($p) => ProductSearch::apply($p, $search))),
                TextColumn::make('expected_quantity')->label(__('inventory.fields.expected'))->alignCenter()
                    ->visible(fn () => $this->canValidate()),
                TextInputColumn::make('counted_quantity')->label(__('inventory.fields.counted'))
                    ->type('number')->rules(['nullable', 'integer', 'min:0'])
                    ->disabled(! $editable)
                    ->updateStateUsing(function (InventoryCountLine $record, $state) {
                        $value = $state === '' || $state === null ? null : (int) $state;
                        app(InventoryCountActions::class)->saveCounts($this->count(), [$record->id => $value], auth()->user());

                        return $value;
                    }),
                TextColumn::make('difference')->label(__('inventory.fields.difference'))->alignCenter()
                    ->state(fn (InventoryCountLine $r) => $r->difference())
                    ->formatStateUsing(fn ($state) => $state === null ? '' : (($state > 0 ? '+' : '').$state))
                    ->color(fn ($state) => $state ? ($state > 0 ? 'success' : 'danger') : null)
                    ->visible(fn () => $this->canValidate()),
            ])
            ->filters([
                TernaryFilter::make('counted')->label(__('inventory.fields.counted'))
                    ->queries(true: fn (Builder $query) => $query->whereNotNull('counted_quantity'), false: fn (Builder $query) => $query->whereNull('counted_quantity')),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $count = $this->count();
        $user = auth()->user();
        $actions = app(InventoryCountActions::class);
        $run = function (callable $callback, string $message) {
            try {
                $callback();
                Notification::make()->success()->title(__($message))->send();
                $this->redirect(InventoryCountResource::getUrl('count', ['record' => $this->count()]));
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('submit')->label(__('inventory.actions.submit'))->color('primary')->requiresConfirmation()
                ->visible($count->status === InventoryCountStatus::Draft && $user->can(Permission::InventoryCount->value))
                ->action(fn () => $run(fn () => $actions->submit($count, auth()->user()), 'inventory.messages.submitted')),
            Action::make('approve')->label(__('inventory.actions.approve'))->color('success')->requiresConfirmation()
                ->visible($count->status === InventoryCountStatus::Submitted && $user->can(Permission::InventoryApprove->value))
                ->action(fn () => $run(fn () => $actions->approve($count, auth()->user()), 'inventory.messages.approved')),
            Action::make('cancel')->label(__('inventory.actions.cancel'))->color('danger')->requiresConfirmation()
                ->visible(in_array($count->status, [InventoryCountStatus::Draft, InventoryCountStatus::Submitted], true) && $user->can(Permission::InventoryCount->value))
                ->action(fn () => $run(fn () => $actions->cancel($count, auth()->user()), 'inventory.messages.cancelled')),
        ];
    }
}
