@php
    $c = fn (string $k) => $company[$k] ?? null;
    $legal = implode(' - ', array_filter([
        $c('legal.rc') ? 'RC : '.$c('legal.rc') : null, $c('legal.if') ? 'I.F : '.$c('legal.if') : null, $c('legal.patente') ? 'T.P : '.$c('legal.patente') : null,
    ]));
    $legal2 = implode(' ', array_filter([$c('legal.cnss') ? 'CNSS N° : '.$c('legal.cnss') : null, $c('legal.ice') ? 'ICE : '.$c('legal.ice') : null]));
@endphp
<div style="text-align: center; font-size: 7.5pt; color: #333;">
    @if ($c('documents.footer_brands'))<div style="font-weight: bold; font-size: 8pt; letter-spacing: 1px; margin-bottom: 2mm;">{{ $c('documents.footer_brands') }}</div>@endif
    {{ trim(implode(' - ', array_filter([$c('company.address'), $c('company.city')]))) }}@if ($legal) - {{ $legal }}@endif<br>
    {{ $legal2 }}<br>
    @if ($c('company.phone'))Tel : {{ $c('company.phone') }}@endif @if ($c('company.email')) - Email : {{ $c('company.email') }}@endif
    @if ($c('bank.rib'))<br>{{ $c('bank.name') }} – RIB : {{ $c('bank.rib') }}@endif
    <div style="text-align: right; font-size: 7pt; color: #777;">{{ $number }} – {PAGENO}/{nbpg}</div>
</div>
