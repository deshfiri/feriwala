@php
    /** $gateway: array{name: string, label: string} */
    $logoPath = 'assets/img/gateways/'.$gateway['name'].'.png';
    $hasLogo = is_file(public_path($logoPath));
@endphp
@if ($hasLogo)
    <img class="gateway-logo" src="{{ '/'.$logoPath }}" alt="{{ $gateway['label'] }}" height="28">
@else
    <strong>{{ $gateway['label'] }}</strong>
@endif
