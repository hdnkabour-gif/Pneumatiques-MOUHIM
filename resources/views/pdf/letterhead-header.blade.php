@php $accent = $company['documents.accent_color'] ?? '#D4C21F'; @endphp
<table width="100%" style="border-collapse: collapse;">
    <tr>
        <td style="width: 75%; vertical-align: middle;">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 22mm;">
            @else
                <span style="font-size: 24pt; font-weight: bold; color: #222;">{{ $company['company.name'] }}</span><br>
                <span style="font-size: 18pt; font-weight: bold; color: {{ $accent }};">PM</span>
            @endif
        </td>
        <td style="width: 25%; text-align: right; vertical-align: top;">
            <img src="{{ $corner }}" style="width: 32mm;">
        </td>
    </tr>
</table>
