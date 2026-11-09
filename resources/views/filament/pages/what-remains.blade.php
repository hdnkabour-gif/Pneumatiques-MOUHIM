@php
    $receivables = $this->receivables(); $cheques = $this->cheques(); $quotes = $this->quotes();
    $workshop = $this->workshop(); $transfers = $this->transfers(); $requests = $this->requests(); $approvals = $this->approvals();
@endphp
<x-filament-panels::page>
<style>
    .wr { display: grid; gap: 10px; }
    .wr details { border: 1px solid var(--gray-200); border-radius: 12px; background: #fff; }
    .dark .wr details { background: var(--gray-900); border-color: var(--gray-700); }
    .wr summary { list-style: none; cursor: pointer; padding: 14px; display: flex; justify-content: space-between; gap: 8px; align-items: center; font-weight: 700; font-size: 1.05rem; }
    .wr summary::-webkit-details-marker { display: none; }
    .wr .n { background: var(--gray-100); border-radius: 999px; padding: 2px 10px; font-size: .9rem; }
    .dark .wr .n { background: var(--gray-800); }
    .wr .big { color: var(--danger-600); }
    .wr .row { display: flex; justify-content: space-between; gap: 8px; padding: 10px 14px; border-top: 1px solid var(--gray-100); align-items: center; flex-wrap: wrap; }
    .dark .wr .row { border-color: var(--gray-800); }
    .wr .muted { color: var(--gray-500); font-size: .85rem; }
    .wr a.btn { padding: 6px 12px; border-radius: 8px; background: var(--success-600); color: #fff; font-weight: 600; text-decoration: none; }
    .wr .empty { padding: 10px 14px; color: var(--gray-500); }
</style>
<div class="wr">
    <details @if ($receivables->isNotEmpty()) open @endif>
        <summary><span>💰 {{ __('remains.receivables') }}</span><span class="big">{{ $this->money((int) $receivables->sum('due')) }} <span class="n">{{ $receivables->count() }}</span></span></summary>
        @forelse ($receivables as $r)
            <div class="row">
                <span><b>{{ $r->customer->name }}</b><br><span class="muted">{{ __('remains.days', ['days' => $r->days]) }} · {{ $r->count }} {{ __('remains.documents') }}</span></span>
                <span><b>{{ $this->money($r->due) }}</b> @if ($r->whatsapp)<a class="btn" href="{{ $r->whatsapp }}" target="_blank">WhatsApp</a>@endif</span>
            </div>
        @empty <div class="empty">—</div> @endforelse
    </details>

    <details>
        <summary><span>🧾 {{ __('remains.cheques') }}</span><span>{{ $this->money((int) $cheques->sum('amount_cents')) }} <span class="n">{{ $cheques->count() }}</span></span></summary>
        @forelse ($cheques as $c)
            <div class="row"><span><b>{{ $c->customer?->name ?? '—' }}</b><br><span class="muted">n° {{ $c->cheque_number }} · {{ $c->bank }}</span></span>
                <span><b>{{ $this->money($c->amount_cents) }}</b><br><span class="muted">{{ $c->due_date?->format('d/m/Y') ?? '—' }}</span></span></div>
        @empty <div class="empty">—</div> @endforelse
    </details>

    <details>
        <summary><span>📝 {{ __('remains.quotes') }}</span><span>{{ $this->money((int) $quotes->sum('total_ttc_cents')) }} <span class="n">{{ $quotes->count() }}</span></span></summary>
        @forelse ($quotes as $q)
            <div class="row"><a href="{{ \App\Filament\Resources\Sales\SaleResource::getUrl('view', ['record' => $q]) }}"><b>{{ $q->number }}</b> {{ $q->customer?->name }}</a>
                <span>{{ $this->money($q->total_ttc_cents) }} <span class="muted">→ {{ $q->valid_until?->format('d/m') }}</span></span></div>
        @empty <div class="empty">—</div> @endforelse
    </details>

    <details>
        <summary><span>🔧 {{ __('remains.workshop') }}</span><span class="n">{{ $workshop->count() }}</span></summary>
        @forelse ($workshop as $o)
            <div class="row"><span><b><bdi dir="ltr">{{ $o->vehicle?->label() ?? $o->number }}</bdi></b> <span class="muted">{{ $o->site->code }}</span></span><span>{{ $o->status->label() }}</span></div>
        @empty <div class="empty">—</div> @endforelse
    </details>

    <details>
        <summary><span>🚚 {{ __('remains.transfers') }}</span><span class="n">{{ $transfers->count() + $requests->count() }}</span></summary>
        @foreach ($transfers as $t)
            <div class="row"><span><b>{{ $t->number }}</b> {{ $t->fromSite->code }} → {{ $t->toSite->code }}</span><span class="muted">{{ __('enums.transfer_status.in_transit') }}</span></div>
        @endforeach
        @foreach ($requests as $rq)
            <div class="row"><span><b>{{ $rq->number }}</b> {{ $rq->site->code }}</span><span class="muted">{{ __('enums.replenishment_status.pending') }}</span></div>
        @endforeach
        @if ($transfers->isEmpty() && $requests->isEmpty()) <div class="empty">—</div> @endif
    </details>

    @if (auth()->user()->can(\App\Enums\Permission::ApprovalsDecide->value))
        <details @if ($approvals->isNotEmpty()) open @endif>
            <summary><span>🔒 {{ __('remains.approvals') }}</span><span class="n">{{ $approvals->count() }}</span></summary>
            @forelse ($approvals as $a)
                <div class="row"><a href="{{ \App\Filament\Resources\ApprovalRequests\ApprovalRequestResource::getUrl('view', ['record' => $a]) }}"><b>{{ $a->type->label() }}</b> – {{ $a->requester?->name }}</a><span class="muted">{{ $a->reason }}</span></div>
            @empty <div class="empty">—</div> @endforelse
        </details>
    @endif
</div>
</x-filament-panels::page>
