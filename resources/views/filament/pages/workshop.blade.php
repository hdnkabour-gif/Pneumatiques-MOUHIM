<x-filament-panels::page>
<style>
    .ws-cols { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 900px) { .ws-cols { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); align-items: start; } }
    .ws-col { display: grid; gap: 10px; }
    .ws-title { font-weight: 800; display: flex; justify-content: space-between; }
    .ws-card { border: 1px solid var(--gray-200); border-radius: 12px; padding: 12px; display: grid; gap: 8px; background: #fff; }
    .dark .ws-card { background: var(--gray-900); border-color: var(--gray-700); }
    .ws-btn { min-height: 52px; padding: 8px 14px; border-radius: 10px; font-weight: 700; border: 1px solid var(--gray-300); background: transparent; color: inherit; cursor: pointer; font-size: 1rem; flex: 1 1 auto; }
    .ws-btn.go { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
    .ws-btn.ok { background: var(--success-600); border-color: var(--success-600); color: #fff; }
    .ws-btn.on { background: var(--gray-800); color: #fff; }
    .ws-btn.sm { min-height: 40px; font-weight: 600; font-size: .9rem; }
    .ws-row { display: flex; gap: 6px; flex-wrap: wrap; }
    .ws-muted { color: var(--gray-500); font-size: .85rem; }
    .ws-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
    details > summary { cursor: pointer; color: var(--primary-600); font-weight: 600; }
</style>

@if ($this->sites()->count() > 1)
    <div class="ws-row">
        <button type="button" class="ws-btn sm {{ $siteId === null ? 'on' : '' }}" style="flex: 0 0 auto" wire:click="$set('siteId', null)">{{ __('work_orders.all_sites') }}</button>
        @foreach ($this->sites() as $s)
            <button type="button" class="ws-btn sm {{ $siteId === $s->id ? 'on' : '' }}" style="flex: 0 0 auto" wire:click="$set('siteId', {{ $s->id }})">{{ $s->code }}</button>
        @endforeach
    </div>
@endif

<div class="ws-cols" wire:poll.30s>
    @foreach ($this->columns() as $status => $orders)
        <div class="ws-col">
            <div class="ws-title"><span>{{ \App\Enums\WorkOrderStatus::from($status)->label() }}</span><span>{{ $orders->count() }}</span></div>
            @forelse ($orders as $o)
                <div class="ws-card" wire:key="wo-{{ $o->id }}">
                    <div style="font-weight: 800; font-size: 1.1rem;">🚗 <bdi dir="ltr">{{ $o->vehicle?->label() ?? __('work_orders.no_vehicle') }}</bdi></div>
                    <div class="ws-muted">{{ $o->number }} · {{ $o->site->code }} · {{ $o->customer?->name ?? __('sales.walk_in') }}@if ($o->mileage) · {{ number_format($o->mileage, 0, ',', ' ') }} km @endif</div>
                    <div>
                        @foreach ($o->sale?->lines ?? [] as $line)
                            <div>{{ $line->kind === 'service' ? '🔧' : '•' }} {{ $line->quantity }} × {{ $line->description }}</div>
                        @endforeach
                    </div>
                    @if (! in_array($status, ['delivered'], true))
                        <div class="ws-row">
                            @foreach ($this->techniciansFor($o->site_id) as $t)
                                <button type="button" class="ws-btn sm {{ $o->technicians->contains($t) ? 'on' : '' }}" wire:click="toggleTechnician({{ $o->id }}, {{ $t->id }})">{{ $t->name }}</button>
                            @endforeach
                        </div>
                        <div class="ws-row">
                            @foreach (\App\Actions\WorkOrders\WorkOrderActions::TRANSITIONS[$status] as $to)
                                <button type="button" class="ws-btn {{ in_array($to, ['done', 'delivered'], true) ? 'ok' : ($to === 'in_progress' ? 'go' : '') }}" wire:click="move({{ $o->id }}, '{{ $to }}')">{{ __('work_orders.actions.'.$to) }}</button>
                            @endforeach
                        </div>
                        @if ($status === 'done' && ($wa = $this->readyMessageUrl($o)))
                            <a class="ws-btn sm ok" style="text-align: center; text-decoration: none;" href="{{ $wa }}" target="_blank">WhatsApp – {{ __('work_orders.ready') }}</a>
                        @endif
                        <details>
                            <summary>{{ __('work_orders.notes') }}</summary>
                            <div style="display: grid; gap: 6px; margin-top: 6px;">
                                <textarea class="ws-input" rows="2" wire:model="edit.{{ $o->id }}.notes" placeholder="{{ __('work_orders.notes_placeholder') }}"></textarea>
                                @if ($o->vehicle)
                                    <input class="ws-input" inputmode="numeric" wire:model="edit.{{ $o->id }}.next_oil_km" placeholder="{{ __('customers.vehicles.next_oil_change_km') }}">
                                @endif
                                <button type="button" class="ws-btn sm" wire:click="saveNote({{ $o->id }})">{{ __('work_orders.save') }}</button>
                                @if ($this->canCancel())
                                    <button type="button" class="ws-btn sm" style="color: var(--danger-600)" wire:click="move({{ $o->id }}, 'cancelled')" wire:confirm="{{ __('work_orders.confirm_cancel') }}">{{ __('work_orders.actions.cancelled') }}</button>
                                @endif
                            </div>
                        </details>
                    @endif
                </div>
            @empty
                <div class="ws-muted">—</div>
            @endforelse
        </div>
    @endforeach
</div>
</x-filament-panels::page>
