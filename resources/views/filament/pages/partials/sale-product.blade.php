@php $bulk = $p->isBulk(); $here = $this->stockHere($p); $stocked = $p->isStocked(); $depot = $stocked ? $this->depotStock($p) : 0; @endphp
<div class="pm-item" style="cursor: default;">
    <span class="pm-grow">
        <b>{{ $p->displayName() }}</b>
        @if ($p->brand)<span class="pm-muted">{{ $p->brand->name }}</span>@endif
        <br>
        @if ($stocked)
            <span class="pm-badge {{ $here > 0 ? 'ok' : 'no' }}">{{ __('sales.screen.here') }} {{ $here }}</span>
            <span class="pm-muted">{{ $this->stockElsewhere($p) }}</span>
        @endif
    </span>
    <span class="pm-row" style="justify-content: flex-end;">
        <b>{{ $this->money($p->priceFor($customer?->price_level?->value ?? 'retail')) }}{{ $bulk ? ' / L' : '' }}</b>
        @if ($bulk)
            @forelse ($this->drumsFor($p) as $drum)
                <button type="button" class="pm-btn primary" style="min-height: 44px;" wire:click="addDrum({{ $drum->id }})">+ {{ $drum->code }} ({{ $drum->remainingLitres() }})</button>
            @empty
                <span class="pm-badge no">{{ __('sales.screen.no_drum') }}</span>
            @endforelse
        @else
        @if ($stocked && $here <= 0 && $depot > 0 && $this->canRequestFromDepot())
            <button type="button" class="pm-btn" style="min-height: 44px;" wire:click="requestFromDepot({{ $p->id }})">📦 {{ __('sales.screen.ask_depot') }}</button>
        @endif
        <button type="button" class="pm-btn primary" style="min-height: 44px; min-width: 52px;" wire:click="addProduct({{ $p->id }})" @disabled($stocked && $here <= 0)>+</button>
        @endif
    </span>
</div>
