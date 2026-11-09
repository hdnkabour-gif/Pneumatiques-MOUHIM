@php
    $t = $this->total(); $y = $this->total($this->day()->subDay());
    $diff = $y['sales'] > 0 ? round(($t['sales'] - $y['sales']) * 100 / $y['sales']) : null;
    $alerts = $this->alerts();
@endphp
<x-filament-panels::page>
<style>
    .td { display: grid; gap: 12px; }
    .td-card { border: 1px solid var(--gray-200); border-radius: 14px; padding: 14px; background: #fff; display: grid; gap: 8px; }
    .dark .td-card { background: var(--gray-900); border-color: var(--gray-700); }
    .td-big { font-size: 2rem; font-weight: 800; }
    .td-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }
    .td-grid div small, .td-muted { color: var(--gray-500); font-size: .85rem; display: block; }
    .td-grid div b { font-size: 1.1rem; }
    .td-alert { padding: 10px 12px; border-radius: 10px; background: var(--warning-50); color: var(--warning-800); border: 1px solid var(--warning-300); }
    .dark .td-alert { background: transparent; }
    .td-bar { height: 10px; border-radius: 999px; background: var(--gray-200); overflow: hidden; }
    .td-bar span { display: block; height: 100%; background: var(--primary-600); }
    .td-row { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
</style>
<div class="td" wire:poll.60s>
    @foreach ($alerts as $a)<div class="td-alert">⚠ {{ $a }}</div>@endforeach

    <div class="td-card">
        <div class="td-row"><span class="td-muted">{{ __('today.sales_today') }} · {{ $t['count'] }} {{ __('today.sales_count') }}</span>
            @if ($diff !== null)<span style="font-weight: 700; color: {{ $diff >= 0 ? 'var(--success-600)' : 'var(--danger-600)' }}">{{ $diff >= 0 ? '+' : '' }}{{ $diff }} % {{ __('today.vs_yesterday') }}</span>@endif
        </div>
        <div class="td-big">{{ $this->money($t['sales']) }}</div>
        <div class="td-grid">
            <div><small>💵 {{ __('today.cash') }}</small><b>{{ $this->money($t['cash']) }}</b></div>
            <div><small>💳 {{ __('today.card') }}</small><b>{{ $this->money($t['card']) }}</b></div>
            <div><small>🧾 {{ __('today.cheque') }}</small><b>{{ $this->money($t['cheque']) }}</b></div>
            <div><small>📒 {{ __('today.credit') }}</small><b style="color: var(--danger-600)">{{ $this->money($t['credit']) }}</b></div>
            <div><small>📤 {{ __('today.expenses') }}</small><b>{{ $this->money($t['expenses']) }}</b></div>
            @if ($this->canSeeMargin())
                <div><small>📈 {{ __('today.margin') }}</small><b>{{ $t['margin'] === null ? __('today.margin_incomplete') : $this->money($t['margin']) }}</b></div>
            @endif
        </div>
    </div>

    @foreach ($this->sites() as $site)
        @php $f = $this->figures($site); $m = $this->month($site); @endphp
        <div class="td-card">
            <div class="td-row"><b>{{ $site->name }}</b><b>{{ $this->money($f['sales']) }}</b></div>
            <div class="td-muted">{{ __('today.month') }} : {{ $this->money($m['done']) }} @if ($m['target']) / {{ $this->money($m['target']) }} ({{ min(999, (int) round($m['done'] * 100 / max(1, $m['target']))) }} %) @endif</div>
            @if ($m['target'])<div class="td-bar"><span style="width: {{ min(100, (int) round($m['done'] * 100 / max(1, $m['target']))) }}%"></span></div>@endif
        </div>
    @endforeach

    <div class="td-card">
        <b>{{ __('today.live') }}</b>
        @forelse ($this->feed() as $a)
            <div class="td-row"><span>{{ __('today.events.'.$a->event) }} {{ $a->properties['number'] ?? '' }} <span class="td-muted" style="display: inline">{{ $a->causer?->name }}</span></span>
                <span class="td-muted">{{ $a->created_at->timezone(config('mouhim.timezone'))->format('H:i') }}</span></div>
        @empty <span class="td-muted">—</span> @endforelse
    </div>
</div>
</x-filament-panels::page>
