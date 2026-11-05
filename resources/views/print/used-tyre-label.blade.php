@extends('print.layout')

@section('title', $tyre->code)

@section('style')
    .label { width: 62mm; border: 1px dashed #999; padding: 3mm; display: flex; gap: 3mm; align-items: center; }
    .label svg { width: 24mm; height: 24mm; flex: none; }
    .label .code { font-size: 15px; font-weight: 700; }
    .label .size { font-size: 14px; font-weight: 600; }
    @media print { @page { size: 62mm 30mm; margin: 0; } .label { border: 0; } }
@endsection

@section('content')
    <div class="label">
        {!! $qr !!}
        <div>
            <div class="code">{{ $tyre->code }}</div>
            <div class="size">{{ $tyre->sizeLabel() }}</div>
            <div>{{ $tyre->brand?->name }} {{ $tyre->pattern }}</div>
            <div>{{ $tyre->treadDepthMm() ? $tyre->treadDepthMm().' mm' : '' }} @if($tyre->dot_year) · DOT {{ sprintf('%02d%02d', $tyre->dot_week, $tyre->dot_year % 100) }} @endif</div>
            @if ($tyre->price_cents)<div><strong>{{ \App\Support\Money::format($tyre->price_cents) }}</strong></div>@endif
        </div>
    </div>
@endsection
