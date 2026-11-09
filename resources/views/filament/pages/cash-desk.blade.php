@php $s = $this->summary(); $day = $this->day(); $expenses = $this->expenses(); @endphp
<x-filament-panels::page>
<style>
    .cd { display: grid; gap: 12px; }
    .cd-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .cd-box { border: 1px solid var(--gray-200); border-radius: 12px; padding: 12px; background: #fff; }
    .dark .cd-box { background: var(--gray-900); border-color: var(--gray-700); }
    .cd-box small { color: var(--gray-500); display: block; }
    .cd-box b { font-size: 1.25rem; }
    .cd-main b { font-size: 1.8rem; }
    .cd-row { display: flex; justify-content: space-between; gap: 8px; padding: 8px 0; border-top: 1px solid var(--gray-100); flex-wrap: wrap; }
    .cd select { padding: 10px; border-radius: 10px; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
</style>
<div class="cd">
    @if ($this->sites()->count() > 1)
        <select wire:model.live="siteId">@foreach ($this->sites() as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach</select>
    @endif
    @if ($day->isClosed())
        <div class="cd-box" style="border-color: var(--success-400);">🔒 {{ __('cash.closed_by', ['name' => $day->closer?->name, 'time' => $day->closed_at->timezone(config('mouhim.timezone'))->format('H:i')]) }}
            — {{ __('cash.counted') }} {{ $this->money($day->counted_cents) }},
            <b style="color: {{ $day->difference_cents === 0 ? 'var(--success-600)' : 'var(--danger-600)' }}">{{ __('cash.difference') }} {{ $this->money($day->difference_cents) }}</b></div>
    @endif
    <div class="cd-grid">
        <div class="cd-box cd-main"><small>{{ __('cash.expected') }}</small><b>{{ $this->money($s['expected']) }}</b></div>
        <div class="cd-box"><small>{{ __('cash.float') }}</small><b>{{ $this->money($s['float']) }}</b></div>
        <div class="cd-box"><small>{{ __('cash.cash_in') }}</small><b style="color: var(--success-600)">+{{ $this->money($s['cash_in']) }}</b></div>
        <div class="cd-box"><small>{{ __('cash.refunds') }}</small><b>−{{ $this->money($s['refunds']) }}</b></div>
        <div class="cd-box"><small>{{ __('cash.expenses') }}</small><b>−{{ $this->money($s['expenses']) }}</b></div>
        <div class="cd-box"><small>{{ __('cash.card_cheque') }}</small><b>{{ $this->money($s['card'] + $s['cheque']) }}</b></div>
    </div>
    <div class="cd-box">
        <b style="font-size: 1rem;">{{ __('cash.today_expenses') }}</b>
        @forelse ($expenses as $e)
            <div class="cd-row"><span>{{ __('cash.categories.'.$e->category) }} – {{ $e->description }} <small style="display: inline">{{ $e->user?->name }}</small>
                @if ($e->photo)<a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($e->photo) }}" target="_blank">📷</a>@endif</span>
                <b style="font-size: 1rem;">{{ $this->money($e->amount_cents) }}</b></div>
        @empty <div class="cd-row"><small>—</small></div> @endforelse
    </div>
</div>
</x-filament-panels::page>
