@extends('layouts.app')

@section('content')
@php
    $customer = (array) $checkout['customer_json'];
    $address = (array) $checkout['shipping_json'];
    $lines = [];

    foreach ((array) $quote['items'] as $quoteLine) {
        $lines[(string) $quoteLine['sku']] = $quoteLine;
    }

    $canPlace = $stockProblems === [] && ! $codBlocked;
@endphp
<div class="page-head"><h1>{{ t('review.title') }}</h1></div>
@include('partials.steps', ['current' => 3])

@if ($repriced)
    <div class="flash flash-warning" role="alert">
        <strong>{{ t('review.repriced_title') }}</strong>
        <p>{{ t('review.repriced_body') }}</p>
    </div>
@endif

<div class="layout-cart">
    <div>
        <section class="panel" aria-labelledby="items">
            <h2 id="items">{{ t('review.items') }}</h2>
            <ul class="lines compact">
                @foreach ($items as $item)
                    @php
                        $line = $lines[(string) $item['sku']] ?? null;
                    @endphp
                    <li class="line">
                        <div class="line-body">
                            <strong>{{ $item['name'] }}</strong>
                            @if (! empty($item['variant']))<p class="muted small">{{ $item['variant'] }}</p>@endif
                            <p class="muted small">{{ t('review.qty', ['n' => num((int) $item['quantity'])]) }}{{ $line !== null && $line['unit_price'] !== null ? ' × '.money($line['unit_price']) : '' }}</p>
                            @if (in_array((string) $item['sku'], $stockProblems, true))<p class="error small" role="alert">{{ t('cart.out_of_stock') }}</p>@endif
                        </div>
                        <div class="line-total">@if ($line !== null && $line['line_total'] !== null)<strong>{{ money($line['line_total']) }}</strong>@endif</div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="panel" aria-labelledby="ship">
            <h2 id="ship">{{ t('review.deliver_to') }}</h2>
            <p>
                <strong>{{ $customer['name'] ?? '' }}</strong><br>
                {{ $customer['phone'] ?? '' }}@if (! empty($customer['email']))<br>{{ $customer['email'] }}@endif
            </p>
            <p>
                {{ $address['line1'] ?? '' }}@if (! empty($address['line2'])), {{ $address['line2'] }}@endif<br>
                {{ $address['city'] ?? '' }}@if (! empty($address['district'])), {{ $address['district'] }}@endif @if (! empty($address['postcode'])){{ ' '.$address['postcode'] }}@endif

            </p>
            @if (! empty($checkout['customer_note']))<p class="muted">{{ $checkout['customer_note'] }}</p>@endif
            <p><a href="{{ route('checkout.form') }}">{{ t('review.change') }}</a></p>
        </section>

        <section class="panel" aria-labelledby="pay">
            <h2 id="pay">{{ t('checkout.payment') }}</h2>
            @if (! empty($checkout['gateway']))
                @php
                    $gatewayLabel = (string) $checkout['gateway'];
                    foreach ((array) ($site['gateways'] ?? []) as $offered) {
                        if ($offered['name'] === $checkout['gateway']) {
                            $gatewayLabel = $offered['label'];
                        }
                    }
                @endphp
                <p>@include('partials.gateway', ['gateway' => ['name' => (string) $checkout['gateway'], 'label' => $gatewayLabel]])</p>
            @endif
            <p><strong>{{ t('payment.'.$checkout['payment_method']) }}</strong><br><span class="muted">{{ t('payment.'.$checkout['payment_method'].'_help') }}</span></p>
            @if ($codBlocked)
                <p class="error" role="alert">{{ t('review.cod_blocked') }}</p>
                @if (is_array($quote['cod_maximum'] ?? null))<p class="muted small">{{ t('review.cod_max', ['amount' => money($quote['cod_maximum'])]) }}</p>@endif
            @endif
        </section>
    </div>

    <aside class="summary-box" aria-labelledby="sum">
        <h2 id="sum">{{ t('cart.summary') }}</h2>
        <dl class="totals">
            <div><dt>{{ t('totals.subtotal') }}</dt><dd>{{ money($quote['subtotal']) }}</dd></div>
            @if (! \App\Support\Money::isZero($quote['discount']))<div><dt>{{ t('totals.discount') }}</dt><dd>− {{ money($quote['discount']) }}</dd></div>@endif
            <div><dt>{{ t('totals.shipping') }}</dt><dd>{{ money($quote['shipping']) }}</dd></div>
            @if (! \App\Support\Money::isZero($quote['tax']))<div><dt>{{ t('totals.tax') }}</dt><dd>{{ money($quote['tax']) }}</dd></div>@endif
            <div class="grand"><dt>{{ t('totals.total') }}</dt><dd>{{ money($quote['grand_total']) }}</dd></div>
        </dl>

        <form action="{{ route('checkout.place', ['token' => $checkout['token']]) }}" method="post" data-once>
            @csrf
            <p class="muted small">
                {{ t($checkout['payment_method'] === 'cod' ? 'review.cod_note' : 'review.online_note') }}
            </p>
            <button class="btn btn-accent btn-wide" type="submit" {{ $canPlace ? '' : 'disabled' }}>
                {{ t($repriced ? 'review.confirm_new_total' : 'review.place') }}
            </button>
        </form>
        <a class="btn btn-quiet btn-wide" href="{{ route('cart.show') }}">{{ t('review.back_to_cart') }}</a>
    </aside>
</div>
@endsection
