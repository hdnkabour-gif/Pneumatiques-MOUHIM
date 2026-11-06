@php
    $done = $this->doneSale();
    $preview = $done ? ['lines' => [], 'error' => null] : $this->preview();
    $lines = $preview['lines'];
    $total = array_sum(array_column($lines, 'total_ttc_cents'));
    $customer = $this->customer();
    $vehicle = $this->vehicle();
@endphp
<x-filament-panels::page>
<style>
    .pm { --pm-gap: 12px; display: grid; gap: var(--pm-gap); }
    .pm-cols { display: grid; gap: var(--pm-gap); grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 1100px) { .pm-cols { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); align-items: start; } }
    .pm-card { background: var(--pm-card, #fff); border: 1px solid var(--gray-200); border-radius: 12px; padding: 14px; display: grid; gap: 10px; }
    .dark .pm-card { --pm-card: var(--gray-900); border-color: var(--gray-700); }
    .pm-h { font-weight: 700; font-size: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .pm-muted { color: var(--gray-500); font-size: .85rem; }
    .pm-input { width: 100%; font-size: 1.05rem; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
    .pm-input:focus { outline: 2px solid var(--primary-500); border-color: transparent; }
    .pm-input.sm { padding: 8px 10px; font-size: .95rem; }
    .pm-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .pm-grow { flex: 1 1 auto; min-width: 0; }
    .pm-list { display: grid; gap: 6px; }
    .pm-item { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--gray-200); background: transparent; color: inherit; text-align: start; width: 100%; cursor: pointer; }
    .dark .pm-item { border-color: var(--gray-700); }
    .pm-item:hover { border-color: var(--primary-400); }
    .pm-btn { min-height: 48px; padding: 10px 16px; border-radius: 10px; font-weight: 600; border: 1px solid var(--gray-300); background: transparent; color: inherit; cursor: pointer; font-size: 1rem; }
    .pm-btn.on, .pm-btn.primary { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
    .pm-btn.success { background: var(--success-600); border-color: var(--success-600); color: #fff; }
    .pm-btn.danger { border-color: var(--danger-500); color: var(--danger-600); }
    .pm-btn.big { min-height: 60px; font-size: 1.2rem; width: 100%; }
    .pm-btn:disabled { opacity: .5; cursor: not-allowed; }
    .pm-qty { display: inline-flex; align-items: center; gap: 4px; }
    .pm-qty button { width: 44px; height: 44px; border-radius: 10px; border: 1px solid var(--gray-300); background: transparent; color: inherit; font-size: 1.3rem; cursor: pointer; }
    .pm-qty span { min-width: 2.2em; text-align: center; font-weight: 700; font-size: 1.1rem; }
    .pm-total { font-size: 1.8rem; font-weight: 800; }
    .pm-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .8rem; font-weight: 600; background: var(--gray-100); color: var(--gray-700); }
    .dark .pm-badge { background: var(--gray-800); color: var(--gray-200); }
    .pm-badge.ok { background: var(--success-100); color: var(--success-700); }
    .pm-badge.no { background: var(--danger-100); color: var(--danger-700); }
    .pm-alert { padding: 12px; border-radius: 10px; background: var(--danger-50); color: var(--danger-700); border: 1px solid var(--danger-200); }
    .pm-warn { padding: 12px; border-radius: 10px; background: var(--warning-50); color: var(--warning-800); border: 1px solid var(--warning-300); display: grid; gap: 10px; }
    .pm-good { padding: 16px; border-radius: 12px; background: var(--success-50); color: var(--success-800); border: 1px solid var(--success-300); display: grid; gap: 12px; }
    .dark .pm-alert, .dark .pm-warn, .dark .pm-good { background: transparent; }
    .pm-chips { display: flex; gap: 8px; flex-wrap: wrap; }
    .pm-line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 10px; padding: 10px 0; border-bottom: 1px solid var(--gray-200); }
    .dark .pm-line { border-color: var(--gray-700); }
    .pm-end { text-align: end; }
    details > summary { cursor: pointer; color: var(--primary-600); font-weight: 600; padding: 4px 0; }
</style>

<div class="pm">
@if ($done)
    {{-- ------------------------------------------------------------ done --}}
    <div class="pm-good">
        <div style="font-size: 1.4rem; font-weight: 800;">✓ {{ __('sales.screen.done_'.$done->kind->value, ['number' => $done->number]) }}</div>
        <div>{{ __('sales.fields.total_ttc') }} : <b>{{ $this->money($done->total_ttc_cents) }}</b>
            @if ($done->credit_cents > 0) · <span style="color: var(--danger-600)">{{ __('sales.fields.credit') }} : <b>{{ $this->money($done->credit_cents) }}</b></span>@endif
        </div>
        @if ($done->workOrder)
            <div>{{ __('sales.screen.work_order_created', ['number' => $done->workOrder->number]) }}</div>
        @endif
        <div class="pm-row">
            <a class="pm-btn primary" href="{{ route('sales.pdf', $done) }}" target="_blank">🖨 {{ __('sales.actions.print') }}</a>
            @if ($wa = $this->whatsappUrl($done))
                <a class="pm-btn success" href="{{ $wa }}" target="_blank">WhatsApp</a>
            @endif
            <a class="pm-btn" href="{{ $this->saleUrl($done) }}">{{ __('sales.actions.open') }}</a>
            <button type="button" class="pm-btn" wire:click="newSale">+ {{ __('sales.screen.new_sale') }}</button>
        </div>
    </div>
@else
<div class="pm-cols">
    {{-- ============================================================ LEFT --}}
    <div class="pm">
        @if ($this->sites()->count() > 1)
            <div class="pm-row">
                <span class="pm-muted">{{ __('sales.fields.site') }}</span>
                <select class="pm-input sm pm-grow" wire:model.live="siteId">
                    @foreach ($this->sites() as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
            </div>
        @endif

        {{-- ---------------------------------------------------- customer --}}
        <div class="pm-card">
            <div class="pm-h">{{ __('sales.screen.customer') }}
                @if ($customer)<button type="button" class="pm-btn" style="min-height: 36px; padding: 4px 12px;" wire:click="clearCustomer">{{ __('sales.screen.change') }}</button>@endif
            </div>

            @if ($customer)
                <div>
                    <div style="font-weight: 700; font-size: 1.1rem;">{{ $customer->name }}</div>
                    <div class="pm-muted">{{ $customer->phone }} · {{ $customer->price_level->label() }}
                        @if (($due = $customer->outstandingCents()) > 0) · <span style="color: var(--danger-600)">{{ __('customers.fields.outstanding') }} {{ $this->money($due) }}</span>@endif
                    </div>
                </div>
                <div class="pm-chips">
                    @foreach ($customer->vehicles as $v)
                        <button type="button" class="pm-btn {{ $vehicleId === $v->id ? 'on' : '' }}" wire:click="$set('vehicleId', {{ $v->id }})">🚗 <bdi dir="ltr">{{ $v->label() }}</bdi></button>
                    @endforeach
                </div>
                <div class="pm-row">
                    <input class="pm-input sm pm-grow" wire:model="newPlate" placeholder="{{ __('sales.screen.add_plate') }}">
                    <button type="button" class="pm-btn" wire:click="addVehicle">+ 🚗</button>
                </div>
                @if ($vehicle)
                    <input class="pm-input sm" inputmode="numeric" wire:model.blur="mileage" placeholder="{{ __('sales.fields.mileage') }} (km)">
                @endif
            @elseif ($showNewCustomer)
                <div class="pm-list">
                    <input class="pm-input" wire:model="newCustomer.phone" inputmode="tel" placeholder="{{ __('customers.fields.phone') }} *">
                    <input class="pm-input" wire:model="newCustomer.name" placeholder="{{ __('customers.fields.name') }} *">
                    <input class="pm-input" wire:model="newCustomer.plate" placeholder="{{ __('customers.vehicles.plate') }}">
                    <details>
                        <summary>{{ __('app.more_details') }}</summary>
                        <select class="pm-input sm" wire:model="newCustomer.vehicle_model_id">
                            <option value="">{{ __('vehicles.fields.model') }}</option>
                            @foreach ($this->vehicleModelOptions() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                    </details>
                    @error('newCustomer')<div class="pm-alert">{{ $message }}</div>@enderror
                    <div class="pm-row">
                        <button type="button" class="pm-btn primary pm-grow" wire:click="saveNewCustomer">{{ __('sales.screen.save_customer') }}</button>
                        <button type="button" class="pm-btn" wire:click="$set('showNewCustomer', false)">{{ __('sales.screen.cancel') }}</button>
                    </div>
                </div>
            @else
                <input class="pm-input" wire:model.live.debounce.300ms="customerSearch" placeholder="{{ __('sales.screen.customer_search') }}" autocomplete="off">
                <div class="pm-list">
                    @foreach ($this->customerResults() as $c)
                        @forelse ($c->vehicles as $v)
                            <button type="button" class="pm-item" wire:click="selectVehicle({{ $c->id }}, {{ $v->id }})">
                                <span><b>{{ $c->name }}</b> <span class="pm-muted">{{ $c->phone }}</span></span>
                                <span class="pm-badge">🚗 <bdi dir="ltr">{{ $v->label() }}</bdi></span>
                            </button>
                        @empty
                            <button type="button" class="pm-item" wire:click="selectCustomer({{ $c->id }})">
                                <span><b>{{ $c->name }}</b> <span class="pm-muted">{{ $c->phone }}</span></span>
                            </button>
                        @endforelse
                    @endforeach
                </div>
                <div class="pm-row">
                    @if ($this->user()->can(\App\Enums\Permission::CustomersManage->value))
                        <button type="button" class="pm-btn" wire:click="openNewCustomer">+ {{ __('sales.screen.new_customer') }}</button>
                    @endif
                    <span class="pm-muted">{{ __('sales.screen.walk_in_help') }}</span>
                </div>
            @endif
        </div>

        {{-- ---------------------------------------------------- products --}}
        <div class="pm-card">
            <div class="pm-h">{{ __('sales.screen.products') }}</div>
            <input class="pm-input" wire:model.live.debounce.300ms="search" placeholder="{{ __('sales.screen.product_search') }}" autocomplete="off">

            @php $results = $this->productResults(); $used = $this->usedTyreResults(); @endphp
            @if ($results->isNotEmpty() || $used->isNotEmpty())
                <div class="pm-list">
                    @foreach ($results as $p)
                        @include('filament.pages.partials.sale-product', ['p' => $p])
                    @endforeach
                    @foreach ($used as $u)
                        <button type="button" class="pm-item" wire:click="addUsedTyre({{ $u->id }})">
                            <span><b>{{ __('sales.screen.used_tyre') }} {{ $u->sizeLabel() }}</b> <span class="pm-muted">{{ $u->brand?->name }} · {{ $u->code }} · {{ number_format($u->tread_depth_tenths / 10, 1, ',', '') }} mm</span></span>
                            <span><b>{{ $this->money($u->price_cents) }}</b></span>
                        </button>
                    @endforeach
                </div>
            @elseif (mb_strlen(trim($search)) >= 2)
                <div class="pm-muted">{{ __('sales.screen.no_result') }}</div>
            @endif

            @php $suggestions = $this->suggestions(); @endphp
            @if ($suggestions->isNotEmpty() && trim($search) === '')
                <div class="pm-muted" style="font-weight: 600;">✓ {{ __('sales.screen.verified_for', ['vehicle' => $vehicle?->label()]) }}</div>
                <div class="pm-list">
                    @foreach ($suggestions as $p)
                        @include('filament.pages.partials.sale-product', ['p' => $p])
                    @endforeach
                </div>
            @endif

            @php $quick = $this->quickButtons(); @endphp
            @if ($quick->isNotEmpty())
                <div class="pm-muted" style="font-weight: 600;">{{ __('sales.screen.services') }}</div>
                <div class="pm-chips">
                    @foreach ($quick as $q)
                        <button type="button" class="pm-btn" wire:click="addProduct({{ $q->id }})">{{ $q->kind === \App\Enums\ProductKind::Bundle ? '★ ' : '' }}{{ $q->displayName() }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ============================================================ RIGHT --}}
    <div class="pm">
        <div class="pm-card">
            <div class="pm-h">{{ __('sales.screen.cart') }} <span class="pm-badge">{{ count($cart) }}</span></div>
            <div class="pm-chips" role="tablist">
                @foreach (['invoice', 'quote', 'delivery_note'] as $k)
                    @if ($k !== 'delivery_note' || $this->canDeliveryNote())
                        <button type="button" class="pm-btn {{ $kind === $k ? 'on' : '' }}" style="min-height: 40px; padding: 4px 14px;" wire:click="setKind('{{ $k }}')">{{ __('sales.screen.kind_'.$k) }}</button>
                    @endif
                @endforeach
            </div>

            @if ($preview['error'])<div class="pm-alert">{{ $preview['error'] }}</div>@endif

            @forelse ($cart as $i => $item)
                @if (! empty($item['trade_in']))
                    @php $row = collect($lines)->where('kind', 'trade_in')->values()->get(collect($cart)->take($i)->filter(fn ($c) => ! empty($c['trade_in']))->count()); @endphp
                    <div class="pm-line" wire:key="line-{{ $i }}-ti">
                        <div style="font-weight: 600;">↩ {{ $item['trade_in'] === 'tyre' ? __('sales.screen.trade_in_tyre') : __('sales.screen.trade_in_battery') }}</div>
                        <div class="pm-end" style="font-weight: 700; color: var(--success-600);">{{ $row ? $this->money($row['total_ttc_cents']) : '' }}</div>
                        <div class="pm-row" style="grid-column: 1 / -1;">
                            @if ($item['trade_in'] === 'tyre')
                                <input class="pm-input sm pm-grow" wire:model.blur="cart.{{ $i }}.size" placeholder="{{ __('sales.screen.size') }} (205/55 R16)">
                                <input class="pm-input sm" style="width: 6em;" inputmode="decimal" wire:model.blur="cart.{{ $i }}.tread" placeholder="mm">
                            @endif
                            <input class="pm-input sm" style="width: 8em;" inputmode="decimal" wire:model.blur="cart.{{ $i }}.value" placeholder="{{ __('sales.screen.trade_in_value') }}">
                            <button type="button" class="pm-btn danger" style="min-height: 44px; padding: 4px 12px;" wire:click="removeLine({{ $i }})">✕</button>
                        </div>
                    </div>
                    @continue
                @endif
                @php $rows = collect($lines)->filter(fn ($l) => ($item['used_tyre_id'] ?? null) ? $l['used_tyre_id'] === $item['used_tyre_id'] : ($l['product_id'] === $item['product_id'] || $l['bundle_id'] === $item['product_id'])); @endphp
                <div class="pm-line" wire:key="line-{{ $i }}-{{ $item['product_id'] ?? 'u'.$item['used_tyre_id'] }}">
                    <div>
                        <div style="font-weight: 600;">{{ $rows->first()['description'] ?? '…' }}@if ($rows->count() > 1) <span class="pm-muted">+ {{ $rows->count() - 1 }}</span>@endif</div>
                        <div class="pm-muted">
                            {{ $this->money($rows->first()['unit_price_ttc_cents'] ?? 0) }}
                            @if (($rows->first()['discount_bp'] ?? 0) > 0) <s>{{ $this->money($rows->first()['list_price_ttc_cents']) }}</s> −{{ rtrim(rtrim(number_format($rows->first()['discount_bp'] / 100, 2, ',', ''), '0'), ',') }} %@endif
                        </div>
                    </div>
                    <div class="pm-end" style="font-weight: 700;">{{ $this->money($rows->sum('total_ttc_cents')) }}</div>
                    <div class="pm-row">
                        @if (empty($item['used_tyre_id']))
                            <span class="pm-qty">
                                <button type="button" wire:click="setQuantity({{ $i }}, {{ $item['quantity'] - 1 }})">−</button>
                                <span>{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="setQuantity({{ $i }}, {{ $item['quantity'] + 1 }})">+</button>
                            </span>
                            @if (($rows->first()['kind'] ?? null) === 'goods')
                            @foreach ([2, 4] as $n)
                                <button type="button" class="pm-btn {{ $item['quantity'] === $n ? 'on' : '' }}" style="min-height: 44px; padding: 4px 12px;" wire:click="setQuantity({{ $i }}, {{ $n }})">×{{ $n }}</button>
                            @endforeach
                            @endif
                        @endif
                    </div>
                    <div class="pm-row" style="justify-content: flex-end;">
                        <input class="pm-input sm" style="width: 6.5em;" inputmode="decimal" wire:model.blur="cart.{{ $i }}.discount" placeholder="{{ __('sales.screen.discount_pct') }}">
                        <button type="button" class="pm-btn danger" style="min-height: 44px; padding: 4px 12px;" wire:click="removeLine({{ $i }})" title="{{ __('sales.screen.remove') }}">✕</button>
                    </div>
                </div>
            @empty
                <div class="pm-muted">{{ __('sales.screen.empty_cart') }}</div>
            @endforelse

            @if ($lines)
                <div class="pm-row" style="justify-content: space-between;">
                    <span class="pm-muted">{{ __('sales.fields.total_ht') }} {{ $this->money(array_sum(array_column($lines, 'total_ht_cents'))) }} · {{ __('sales.fields.total_vat') }} {{ $this->money(array_sum(array_column($lines, 'total_vat_cents'))) }}</span>
                    <span class="pm-total">{{ $this->money($total) }}</span>
                </div>
            @endif
            @if ($cart && $kind !== 'quote')
                <details>
                    <summary>↩ {{ __('sales.screen.trade_in') }}</summary>
                    <div class="pm-chips" style="margin-top: 6px;">
                        <button type="button" class="pm-btn" wire:click="addTradeIn('tyre')">+ {{ __('sales.screen.trade_in_tyre') }}</button>
                        <button type="button" class="pm-btn" wire:click="addTradeIn('battery')">+ {{ __('sales.screen.trade_in_battery') }}</button>
                    </div>
                </details>
            @endif
            @if ($kind === 'quote' && $this->canReserve() && $lines)
                <label class="pm-row" style="cursor: pointer;"><input type="checkbox" wire:model="reserve" style="width: 22px; height: 22px;"> {{ __('sales.screen.reserve') }}</label>
            @endif
        </div>

        @if ($lines)
            @if ($kind !== 'quote')
            {{-- ------------------------------------------------ payment --}}
            <div class="pm-card">
                <div class="pm-h">{{ __('sales.screen.payment') }}</div>
                <div class="pm-chips">
                    <button type="button" class="pm-btn {{ ! $paymentsTouched ? 'on' : '' }}" wire:click="payAllCash">💵 {{ __('sales.screen.all_cash') }}</button>
                    <button type="button" class="pm-btn" wire:click="addPayment('card')">💳 + {{ \App\Enums\PaymentMethod::Card->label() }}</button>
                    <button type="button" class="pm-btn" wire:click="addPayment('cheque')">🧾 + {{ \App\Enums\PaymentMethod::Cheque->label() }}</button>
                    <button type="button" class="pm-btn" wire:click="addPayment('cash')">💵 + {{ \App\Enums\PaymentMethod::Cash->label() }}</button>
                    <button type="button" class="pm-btn" wire:click="payOnCredit">📒 {{ __('sales.screen.all_credit') }}</button>
                    @foreach ($this->vouchers() as $v)
                        <button type="button" class="pm-btn" wire:click="useVoucher({{ $v->id }})">🎟 {{ $v->number }} ({{ $this->money($v->voucherBalanceCents()) }})</button>
                    @endforeach
                </div>

                @foreach ($payments as $j => $pay)
                    <div class="pm-list" wire:key="pay-{{ $j }}" style="border-top: 1px dashed var(--gray-300); padding-top: 8px;">
                        <div class="pm-row" style="flex-wrap: nowrap;">
                            <span class="pm-badge" style="min-width: 6em; text-align: center;">{{ \App\Enums\PaymentMethod::from($pay['method'])->label() }}{{ isset($pay['label']) ? ' '.$pay['label'] : '' }}</span>
                            <input class="pm-input sm pm-grow" style="width: 0;" inputmode="decimal" wire:model.blur="payments.{{ $j }}.amount" placeholder="0.00">
                            <span class="pm-muted">MAD</span>
                            <button type="button" class="pm-btn danger" style="min-height: 40px; padding: 2px 10px;" wire:click="removePayment({{ $j }})">✕</button>
                        </div>
                        @if ($pay['method'] === 'cheque')
                            <div class="pm-row">
                                <input class="pm-input sm pm-grow" wire:model.blur="payments.{{ $j }}.cheque_number" placeholder="{{ __('sales.fields.cheque_number') }} *">
                                <input class="pm-input sm pm-grow" wire:model.blur="payments.{{ $j }}.bank" placeholder="{{ __('sales.fields.bank') }} *">
                            </div>
                            <div class="pm-row">
                                <input class="pm-input sm pm-grow" wire:model.blur="payments.{{ $j }}.drawer" placeholder="{{ __('sales.fields.drawer') }}">
                                <input class="pm-input sm pm-grow" type="date" wire:model.blur="payments.{{ $j }}.due_date" title="{{ __('sales.fields.due_date') }}">
                            </div>
                        @endif
                        @if ($pay['method'] === 'cash' && count($payments) === 1)
                            <div class="pm-row">
                                <input class="pm-input sm pm-grow" inputmode="decimal" wire:model.live.debounce.400ms="cashGiven" placeholder="{{ __('sales.screen.cash_given') }}">
                                @if (($change = $this->changeDue()) !== null)
                                    <span style="font-weight: 700; {{ $change < 0 ? 'color: var(--danger-600)' : '' }}">{{ __('sales.screen.change_due') }} : {{ $this->money(max(0, $change)) }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                @php $credit = max(0, $total - $this->paidCents()); @endphp
                <div class="pm-row" style="justify-content: space-between; font-weight: 700;">
                    <span>{{ __('sales.screen.paid_now') }} : {{ $this->money($this->paidCents()) }}</span>
                    <span style="{{ $credit > 0 ? 'color: var(--danger-600)' : 'color: var(--success-600)' }}">{{ __('sales.screen.on_credit') }} : {{ $this->money($credit) }}</span>
                </div>
                @if ($credit > 0 && ! $customer)<div class="pm-alert">{{ __('sales.errors.credit_needs_customer') }}</div>@endif
            </div>

            @endif

            @if ($kind !== 'quote' && $this->hasServices())
                <div class="pm-card">
                    <div class="pm-h">{{ __('sales.screen.technicians') }}</div>
                    <div class="pm-chips">
                        @forelse ($this->technicians() as $t)
                            <button type="button" class="pm-btn {{ in_array($t->id, $technicianIds, true) ? 'on' : '' }}" style="min-height: 56px; min-width: 8em;" wire:click="toggleTechnician({{ $t->id }})">🔧 {{ $t->name }}</button>
                        @empty
                            <span class="pm-muted">{{ __('sales.screen.no_technician') }}</span>
                        @endforelse
                    </div>
                </div>
            @endif

            <details class="pm-card">
                <summary>{{ __('app.more_details') }}</summary>
                <input class="pm-input sm" wire:model.blur="poNumber" placeholder="{{ __('sales.fields.po_number') }}{{ $customer?->requires_po_number ? ' *' : '' }}">
                <textarea class="pm-input sm" rows="2" wire:model.blur="notes" placeholder="{{ __('sales.fields.notes') }}"></textarea>
            </details>

            @error('sale')<div class="pm-alert">{{ $message }}</div>@enderror

            @if ($neededApprovals)
                {{-- -------------------------------------------- approval --}}
                <div class="pm-warn">
                    <div style="font-weight: 700;">🔒 {{ __('sales.screen.approval_needed', ['types' => implode(', ', $this->approvalLabels())]) }}</div>
                    @if ($approvalSent)
                        <div>{{ __('sales.screen.approval_sent_help') }}</div>
                        <button type="button" class="pm-btn primary" wire:click="retry">{{ __('sales.screen.retry') }}</button>
                    @else
                        <div class="pm-row">
                            <input class="pm-input sm pm-grow" type="password" inputmode="numeric" autocomplete="off" wire:model="pin" placeholder="{{ __('sales.screen.owner_pin') }}">
                            <button type="button" class="pm-btn primary" wire:click="approveWithPin">{{ __('sales.screen.approve_pin') }}</button>
                        </div>
                        @error('pin')<div class="pm-alert">{{ $message }}</div>@enderror
                        <div class="pm-muted">{{ __('sales.screen.or_send') }}</div>
                        <div class="pm-row">
                            <input class="pm-input sm pm-grow" wire:model="approvalReason" placeholder="{{ __('sales.screen.approval_reason') }}">
                            <button type="button" class="pm-btn" wire:click="sendApproval">📨 {{ __('sales.screen.send_request') }}</button>
                        </div>
                        @error('approvalReason')<div class="pm-alert">{{ $message }}</div>@enderror
                    @endif
                </div>
            @else
                <button type="button" class="pm-btn success big" wire:click="confirm" wire:loading.attr="disabled" @disabled($preview['error'])>
                    <span wire:loading.remove wire:target="confirm">✓ {{ __('sales.screen.confirm_'.$kind, ['total' => $this->money($total)]) }}</span>
                    <span wire:loading wire:target="confirm">…</span>
                </button>
            @endif
        @endif
    </div>
</div>
@endif
</div>
</x-filament-panels::page>
