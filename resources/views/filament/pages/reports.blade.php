@php $r = $this->report(); $s = $r->summary(); $costs = $this->canSeeCosts(); @endphp
<x-filament-panels::page>
<style>
    .rp { display: grid; gap: 12px; }
    .rp-card { border: 1px solid var(--gray-200); border-radius: 12px; padding: 12px; background: #fff; overflow-x: auto; }
    .dark .rp-card { background: var(--gray-900); border-color: var(--gray-700); }
    .rp-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
    .rp-grid small { color: var(--gray-500); display: block; }
    .rp-grid b { font-size: 1.2rem; }
    .rp table { width: 100%; border-collapse: collapse; font-size: .92rem; }
    .rp th, .rp td { padding: 6px 8px; border-bottom: 1px solid var(--gray-100); text-align: start; white-space: nowrap; }
    .dark .rp th, .dark .rp td { border-color: var(--gray-800); }
    .rp td.n, .rp th.n { text-align: end; }
    .rp-btn { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--gray-300); background: transparent; color: inherit; cursor: pointer; }
    .rp input, .rp select { padding: 8px; border-radius: 8px; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
    .rp h3 { font-weight: 700; margin-bottom: 6px; }
</style>
<div class="rp">
    <div class="rp-card" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        @foreach (['today', 'week', 'month', 'last_month', 'year'] as $p)
            <button type="button" class="rp-btn" wire:click="period('{{ $p }}')">{{ __('reports.periods.'.$p) }}</button>
        @endforeach
        <input type="date" wire:model.live="from"> → <input type="date" wire:model.live="to">
        @if ($this->sites()->count() > 1)
            <select wire:model.live="siteId"><option value="">{{ __('reports.all_sites') }}</option>
                @foreach ($this->sites() as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach</select>
        @endif
    </div>

    <div class="rp-card rp-grid">
        <div><small>{{ __('reports.cols.revenue') }}</small><b>{{ $this->money($s->ttc) }}</b></div>
        <div><small>{{ __('reports.cols.docs') }}</small><b>{{ (int) $s->docs }}</b></div>
        <div><small>{{ __('reports.cols.basket') }}</small><b>{{ $this->money($s->basket) }}</b></div>
        @if ($costs)<div><small>{{ __('reports.cols.margin') }}</small><b>{{ $s->incomplete ? __('today.margin_incomplete') : $this->money($s->margin) }}</b></div>@endif
    </div>

    @foreach (['site', 'seller', 'category', 'brand'] as $by)
        <div class="rp-card"><h3>{{ __('reports.by.'.$by) }}</h3>
            <table><tr><th>{{ __('reports.cols.label') }}</th><th class="n">{{ __('reports.cols.docs') }}</th><th class="n">TTC</th>@if ($costs)<th class="n">{{ __('reports.cols.margin') }}</th>@endif</tr>
                @forelse ($r->by($by) as $row)
                    <tr><td>{{ $row->label }}</td><td class="n">{{ (int) $row->docs }}</td><td class="n">{{ $this->money($row->ttc) }}</td>
                        @if ($costs)<td class="n">{{ $row->incomplete ? '⚠ '.$this->money($row->margin) : $this->money($row->margin) }}</td>@endif</tr>
                @empty <tr><td colspan="4">—</td></tr> @endforelse
            </table>
        </div>
    @endforeach

    <div class="rp-card"><h3>{{ __('reports.top') }}</h3>
        <table><tr><th>{{ __('reports.cols.label') }}</th><th class="n">{{ __('reports.cols.qty') }}</th><th class="n">TTC</th></tr>
            @forelse ($r->topProducts() as $row)<tr><td>{{ $row->label }}</td><td class="n">{{ (int) $row->qty }}</td><td class="n">{{ $this->money($row->ttc) }}</td></tr>
            @empty <tr><td colspan="3">—</td></tr> @endforelse
        </table>
    </div>

    <div class="rp-card"><h3>{{ __('reports.aged') }}</h3>
        <table><tr><th>{{ __('reports.cols.customer') }}</th><th class="n">0–30 j</th><th class="n">31–60 j</th><th class="n">61–90 j</th><th class="n">&gt; 90 j</th><th class="n">Total</th></tr>
            @forelse ($r->agedBalance() as $a)
                <tr><td>{{ $a->customer }}</td><td class="n">{{ $this->money($a->b0) }}</td><td class="n">{{ $this->money($a->b30) }}</td><td class="n">{{ $this->money($a->b60) }}</td>
                    <td class="n" style="{{ $a->b90 ? 'color: var(--danger-600); font-weight: 700' : '' }}">{{ $this->money($a->b90) }}</td><td class="n"><b>{{ $this->money($a->total) }}</b></td></tr>
            @empty <tr><td colspan="6">—</td></tr> @endforelse
        </table>
    </div>

    @if ($costs)
        <div class="rp-card"><h3>{{ __('reports.stock') }}</h3>
            <table><tr><th>{{ __('reports.cols.label') }}</th><th class="n">{{ __('reports.cols.qty') }}</th><th class="n">{{ __('reports.cols.value') }}</th></tr>
                @foreach ($r->stockValue() as $row)
                    <tr><td>{{ $row->label }}</td><td class="n">{{ (int) $row->qty }}</td><td class="n">{{ $this->money($row->value) }}@if ($row->unknown) <small>({{ __('reports.unknown_cost', ['count' => (int) $row->unknown]) }})</small>@endif</td></tr>
                @endforeach
            </table>
        </div>
    @endif
</div>
</x-filament-panels::page>
