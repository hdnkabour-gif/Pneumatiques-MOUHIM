@extends('print.layout')

@section('title', __('transfers.document.title').' '.$transfer->number)

@section('content')
    <div class="row">
        <div>
            <strong>{{ $company['company.name'] }}</strong><br>
            <span class="muted">{{ $company['company.address'] }} {{ $company['company.city'] }}</span>
        </div>
        <div style="text-align:end">
            <h1>{{ __('transfers.document.title') }}</h1>
            <strong>{{ $transfer->number }}</strong><br>
            {{ __('transfers.fields.status') }} : {{ $transfer->status->label() }}
        </div>
    </div>

    <table>
        <tr>
            <th>{{ __('transfers.fields.from') }}</th><td>{{ $transfer->fromSite->code }} – {{ $transfer->fromSite->name }}</td>
            <th>{{ __('transfers.fields.sent_at') }}</th><td>{{ $transfer->sent_at?->format('d/m/Y H:i') }} – {{ $transfer->sender?->name }}</td>
        </tr>
        <tr>
            <th>{{ __('transfers.fields.to') }}</th><td>{{ $transfer->toSite->code }} – {{ $transfer->toSite->name }}</td>
            <th>{{ __('transfers.fields.received_at') }}</th><td>{{ $transfer->received_at?->format('d/m/Y H:i') }} {{ $transfer->receiver?->name }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>{{ __('catalog.fields.sku') }}</th>
                <th>{{ __('transfers.fields.product') }}</th>
                <th class="num">{{ __('transfers.fields.quantity_sent') }}</th>
                <th class="num">{{ __('transfers.fields.quantity_received') }}</th>
                <th>{{ __('transfers.fields.discrepancy_reason') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transfer->lines as $line)
                <tr>
                    <td>{{ $line->product->sku }}</td>
                    <td>{{ $line->product->name }}</td>
                    <td class="num">{{ $line->quantity_sent }}</td>
                    <td class="num">{{ $line->quantity_received ?? '' }}</td>
                    <td>{{ $line->discrepancy_reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($transfer->notes)
        <p class="muted">{{ __('transfers.fields.notes') }} : {{ $transfer->notes }}</p>
    @endif

    <div class="sign">
        <div>{{ __('transfers.document.signature_sender') }}</div>
        <div>{{ __('transfers.document.signature_receiver') }}</div>
    </div>
@endsection
