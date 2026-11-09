@php
    use App\Support\Money;
    use App\Support\AmountInWords;
    $c = fn (string $key) => $company[$key] ?? null;
    $legal = array_filter([
        $c('legal.ice') ? 'ICE : '.$c('legal.ice') : null,
        $c('legal.if') ? 'IF : '.$c('legal.if') : null,
        $c('legal.rc') ? 'RC : '.$c('legal.rc') : null,
        $c('legal.patente') ? 'TP : '.$c('legal.patente') : null,
        $c('legal.cnss') ? 'CNSS : '.$c('legal.cnss') : null,
    ]);
    $customer = $sale->customer;
    $docType = $sale->documentType()->value;
    $kind = $sale->kind->value;
@endphp
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; color: #111; }
    .title { font-size: 18pt; font-weight: bold; }
    .muted { color: #555; }
    table { border-collapse: collapse; width: 100%; }
    .lines th { background: #eee; border: 0.6pt solid #888; padding: 5px; font-size: 8.5pt; text-align: left; }
    .lines td { border: 0.6pt solid #aaa; padding: 5px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .box { border: 0.6pt solid #888; padding: 8px; }
    .totals td { padding: 4px 6px; }
    .grand { font-size: 12pt; font-weight: bold; background: #eee; }
    .words { margin-top: 10px; padding: 8px; border: 0.6pt solid #888; }
    .footer { font-size: 7.5pt; color: #444; text-align: center; margin-top: 18px; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width: 55%; vertical-align: top;">
            @if ($logo)<img src="{{ $logo }}" style="max-height: 60px; max-width: 200px;"><br>@endif
            <span class="title" style="font-size: 14pt;">{{ $c('company.name') }}</span><br>
            @if ($c('company.address')){{ $c('company.address') }}@if ($c('company.city')), {{ $c('company.city') }}@endif<br>@endif
            @if ($c('company.phone'))Tél. : {{ $c('company.phone') }}@endif @if ($c('company.email')) · {{ $c('company.email') }}@endif<br>
            <span class="muted">{{ __('sales.pdf.site') }} : {{ $sale->site->name }}</span>
        </td>
        <td style="width: 45%; vertical-align: top; text-align: right;">
            <span class="title">{{ __('sales.pdf.title_'.$docType) }}</span><br>
            <b>N° {{ $sale->number }}</b><br>
            {{ __('sales.pdf.date') }} : {{ $sale->confirmed_at?->timezone(config('mouhim.timezone'))->format('d/m/Y') }}<br>
            @if ($kind === 'quote' && $sale->valid_until){{ __('sales.pdf.valid_until', ['date' => $sale->valid_until->format('d/m/Y')]) }}<br>@endif
            @if ($kind === 'credit_note' && $sale->originalSale){{ __('sales.pdf.original', ['number' => $sale->originalSale->number]) }}<br>@endif
            @if ($sale->po_number){{ __('sales.fields.po_number') }} : {{ $sale->po_number }}<br>@endif
        </td>
    </tr>
</table>

<table style="margin-top: 12px;">
    <tr>
        <td style="width: 50%;"></td>
        <td class="box" style="width: 50%;">
            <b>{{ __('sales.pdf.customer') }}</b><br>
            @if ($customer)
                {{ $customer->name }}<br>
                @if ($customer->address){{ $customer->address }}@if ($customer->city), {{ $customer->city }}@endif<br>@endif
                @if ($customer->ice)ICE : {{ $customer->ice }}<br>@endif
                @if ($customer->phone)Tél. : {{ $customer->phone }}<br>@endif
            @else
                {{ __('sales.walk_in') }}<br>
            @endif
            @if ($sale->vehicle)
                {{ __('sales.fields.vehicle') }} : {{ $sale->vehicle->label() }}@if ($sale->mileage) – {{ number_format($sale->mileage, 0, ',', ' ') }} km @endif
            @endif
        </td>
    </tr>
</table>

<table class="lines" style="margin-top: 14px;">
    <thead>
        <tr>
            <th style="width: 44%;">{{ __('sales.fields.description') }}</th>
            <th class="num">{{ __('sales.pdf.qty') }}</th>
            <th class="num">{{ __('sales.pdf.unit_ttc') }}</th>
            <th class="num">{{ __('sales.fields.discount') }}</th>
            <th class="num">{{ __('sales.pdf.vat') }}</th>
            <th class="num">{{ __('sales.pdf.total_ht') }}</th>
            <th class="num">{{ __('sales.pdf.total_ttc') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($sale->lines as $line)
            <tr>
                <td>{{ $line->description }}@if ($kind === 'credit_note' && $line->restock === false) <i>({{ __('sales.pdf.damaged') }})</i>@endif</td>
                <td class="num">{{ $line->quantity_ml ? \App\Models\OilDrum::litres($line->quantity_ml) : $line->quantity }}</td>
                <td class="num">{{ Money::format($line->list_price_ttc_cents, false) }}{{ $line->quantity_ml ? ' /L' : '' }}</td>
                <td class="num">{{ $line->discount_bp ? number_format($line->discount_bp / 100, 1, ',', ' ').' %' : '' }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($line->vat_rate_bp / 100, 2, ',', ''), '0'), ',') }} %</td>
                <td class="num">{{ Money::format($line->total_ht_cents, false) }}</td>
                <td class="num">{{ Money::format($line->total_ttc_cents, false) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 10px;">
    <tr>
        <td style="width: 52%; vertical-align: top;">
            <table class="lines">
                <tr><th>{{ __('sales.pdf.vat_rate') }}</th><th class="num">{{ __('sales.pdf.base_ht') }}</th><th class="num">{{ __('sales.pdf.vat_amount') }}</th></tr>
                @foreach ($vat as $row)
                    <tr>
                        <td>{{ rtrim(rtrim(number_format($row['rate'] / 100, 2, ',', ''), '0'), ',') }} %</td>
                        <td class="num">{{ Money::format($row['ht'], false) }}</td>
                        <td class="num">{{ Money::format($row['vat'], false) }}</td>
                    </tr>
                @endforeach
            </table>
            @if ($sale->kind->movesStock() && ($sale->payments->isNotEmpty() || $sale->credit_cents > 0))
                <p style="margin-top: 8px;">
                    <b>{{ __('sales.fields.payments') }} :</b>
                    @foreach ($sale->payments as $p)
                        {{ $p->method->label() }} {{ Money::format($p->pivot->amount_cents) }}@if ($p->cheque_number) (n° {{ $p->cheque_number }}, {{ $p->bank }})@endif@if (! $loop->last) · @endif
                    @endforeach
                    @if ($sale->credit_cents > 0)<br><b>{{ __('sales.pdf.remaining') }} : {{ Money::format($sale->credit_cents) }}</b>@endif
                </p>
            @endif
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 44%; vertical-align: top;">
            <table class="totals box">
                <tr><td>{{ __('sales.pdf.total_ht') }}</td><td class="num">{{ Money::format($sale->total_ht_cents) }}</td></tr>
                @if ($sale->discount_cents > 0)
                    <tr><td class="muted">{{ __('sales.pdf.discount_included') }}</td><td class="num muted">{{ Money::format($sale->discount_cents) }}</td></tr>
                @endif
                <tr><td>{{ __('sales.pdf.total_vat') }}</td><td class="num">{{ Money::format($sale->total_vat_cents) }}</td></tr>
                <tr class="grand"><td>{{ __('sales.pdf.total_ttc') }}</td><td class="num">{{ Money::format($sale->total_ttc_cents) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="words">
    {{ __($kind === 'invoice' ? 'sales.pdf.words' : 'sales.pdf.words_'.$kind, ['amount' => AmountInWords::french($sale->total_ttc_cents)]) }}
</div>

@if ($kind === 'delivery_note')
    <table style="margin-top: 24px;">
        <tr>
            <td class="box" style="width: 48%; height: 70px; vertical-align: top;">{{ __('sales.pdf.signature_customer') }}</td>
            <td style="width: 4%;"></td>
            <td class="box" style="width: 48%; height: 70px; vertical-align: top;">{{ __('sales.pdf.signature_company') }}</td>
        </tr>
    </table>
@endif

<div class="footer">
    {{ implode(' · ', $legal) }}
    @if ($c('bank.rib'))<br>{{ $c('bank.name') }} – RIB : {{ $c('bank.rib') }}@endif
    @if ($c('documents.legal_mentions'))<br>{{ $c('documents.legal_mentions') }}@endif
</div>
</body>
</html>
