<?php

namespace App\Actions\Transfers;

use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Enums\ReplenishmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\ReplenishmentRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\SequenceService;
use Illuminate\Support\Facades\DB;

/** A store asks the depot for goods (§2). The depot turns it into a transfer. */
class CreateReplenishmentRequestAction
{
    public function __construct(private SequenceService $sequences) {}

    /** @param  array<int, int>  $lines  product_id => quantity */
    public function execute(Site $site, Site $source, array $lines, User $user, ?string $notes = null): ReplenishmentRequest
    {
        if (! $user->can(Permission::ReplenishmentRequest->value) || ! $user->canAccessSite($site)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        if ($site->is($source)) {
            throw BusinessRuleException::make('transfers.errors.same_site');
        }

        $lines = array_filter($lines, fn ($qty) => (int) $qty > 0);
        if ($lines === []) {
            throw BusinessRuleException::make('transfers.errors.no_lines');
        }

        return DB::transaction(function () use ($site, $source, $lines, $user, $notes) {
            $request = ReplenishmentRequest::query()->create([
                'number' => $this->sequences->nextDocumentNumber(DocumentType::Replenishment),
                'site_id' => $site->id,
                'source_site_id' => $source->id,
                'status' => ReplenishmentStatus::Pending,
                'requested_by' => $user->id,
                'notes' => $notes,
            ]);

            foreach ($lines as $productId => $quantity) {
                $request->lines()->create(['product_id' => $productId, 'quantity' => (int) $quantity]);
            }

            activity('transfers')->causedBy($user)->performedOn($request)->event('replenishment_requested')
                ->withProperties(['lines' => $lines])->tap(fn ($a) => $a->site_id = $site->id)->log('replenishment_requested');

            return $request;
        });
    }
}
