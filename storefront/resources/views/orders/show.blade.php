@extends('layouts.app')

@section('content')
@php
    $status = (string) ($order['status'] ?? $local['status']);
    $labelFor = static function (string $prefix, string $value): string {
        $key = $prefix.$value;

        return t($key) !== $key ? t($key) : ucfirst(str_replace('_', ' ', $value));
    };
    $statusLabel = $labelFor('status.', $status);
    $payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
    $method = (string) ($payment['method'] ?? $local['payment_method']);
    $totals = is_array($order['totals'] ?? null) ? $order['totals'] : [];
    $items = array_values(array_filter((array) ($order['items'] ?? []), 'is_array'));
    $timeline = array_values(array_filter((array) ($order['timeline'] ?? []), 'is_array'));
    $confirmation = is_array($order['confirmation'] ?? null) ? $order['confirmation'] : null;
    $returnable = is_array($order['returnable'] ?? null) ? $order['returnable'] : [];
    $returns = array_values(array_filter((array) ($order['returns'] ?? []), 'is_array'));
    $reservation = is_array($order['stock_reservation'] ?? null) ? $order['stock_reservation'] : null;

    $awaitingPayment = $status === 'payment_pending';
    $awaitingCode = $status === 'customer_verification_pending';
    $routeParams = ['id' => (string) $local['public_id'], 't' => (string) $local['access_token']];
@endphp
<div class="page-head">
    <h1>{{ t('order.title', ['ref' => (string) ($order['reference'] ?? '')]) }}</h1>
    <p class="status status-{{ preg_replace('/[^a-z_]/', '', $status) ?? '' }}">{{ $statusLabel }}</p>
</div>

@if ($stale)<p class="flash flash-warning" role="status">{{ t('order.stale') }}</p>@endif

@if ($paymentReturn !== '' && $awaitingPayment)
    <p class="flash flash-info" role="status">{{ t('order.payment_processing') }}</p>
@endif

@if ($awaitingPayment)
    <section class="panel highlight" aria-labelledby="pay">
        <h2 id="pay">{{ t('order.pay_title') }}</h2>
        <p>{{ t('order.pay_body') }}</p>
        @if ($reservation !== null && ! empty($reservation['expires_at']))
            <p class="muted small">{{ t('order.held_until', ['time' => gmdate('j M Y, H:i', (int) strtotime((string) $reservation['expires_at'])).' UTC']) }}</p>
        @endif
        <div class="actions">
            <form action="{{ route('orders.pay', $routeParams) }}" method="post">@csrf<button class="btn btn-accent" type="submit">{{ t('order.pay_now') }}</button></form>
            <form action="{{ route('orders.cancel', $routeParams) }}" method="post" data-confirm="{{ t('order.cancel_confirm') }}">@csrf<button class="btn btn-quiet" type="submit">{{ t('order.cancel') }}</button></form>
        </div>
    </section>
@endif

@if ($awaitingCode)
    <section class="panel highlight" aria-labelledby="cod">
        <h2 id="cod">{{ t('order.cod_title') }}</h2>
        <p>{{ t('order.cod_body') }}</p>
        @if ($confirmation !== null && ! empty($confirmation['expires_at']))
            <p class="muted small">{{ t('order.cod_expires', ['time' => gmdate('j M Y, H:i', (int) strtotime((string) $confirmation['expires_at'])).' UTC']) }}</p>
        @endif

        <form class="inline" action="{{ route('orders.confirm', $routeParams) }}" method="post" data-once>
            @csrf
            <label for="code">{{ t('order.code') }}</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" required>
            <button class="btn btn-accent" type="submit">{{ t('order.confirm') }}</button>
        </form>

        <div class="actions">
            <form action="{{ route('orders.code', $routeParams) }}" method="post">@csrf<button class="btn btn-quiet" type="submit">{{ t('order.send_code') }}</button></form>
            <form action="{{ route('orders.cancel', $routeParams) }}" method="post" data-confirm="{{ t('order.cancel_confirm') }}">@csrf<button class="btn btn-quiet" type="submit">{{ t('order.cancel') }}</button></form>
        </div>
    </section>
@endif

<div class="layout-cart">
    <div>
        @if ($items !== [])
            <section class="panel" aria-labelledby="items">
                <h2 id="items">{{ t('review.items') }}</h2>
                <ul class="lines compact">
                    @foreach ($items as $item)
                        <li class="line">
                            <div class="line-body">
                                <strong>{{ $item['name'] ?? $item['sku'] ?? '' }}</strong>
                                @if (! empty($item['variant']))<p class="muted small">{{ $item['variant'] }}</p>@endif
                                <p class="muted small">{{ t('review.qty', ['n' => num((int) ($item['quantity'] ?? 0))]) }}{{ is_array($item['unit_price'] ?? null) ? ' × '.money($item['unit_price']) : '' }}</p>
                            </div>
                            <div class="line-total">@if (is_array($item['line_total'] ?? null))<strong>{{ money($item['line_total']) }}</strong>@endif</div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($timeline !== [])
            <section class="panel" aria-labelledby="tl">
                <h2 id="tl">{{ t('order.timeline') }}</h2>
                <ol class="timeline">
                    @foreach (array_reverse($timeline) as $entry)
                        <li>
                            <strong>{{ $labelFor('status.', (string) ($entry['status'] ?? '')) }}</strong>
                            <span class="muted small">{{ gmdate('j M Y, H:i', (int) strtotime((string) ($entry['at'] ?? 'now'))) }} UTC</span>
                            @if (! empty($entry['note']))<p>{{ $entry['note'] }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if (! empty($order['tracking_number']))
            <section class="panel"><h2>{{ t('order.tracking') }}</h2><p>{{ $order['tracking_number'] }}</p></section>
        @endif

        @if ($returns !== [])
            <section class="panel" aria-labelledby="rets">
                <h2 id="rets">{{ t('return.yours') }}</h2>
                <ul>
                    @foreach ($returns as $return)
                        <li>{{ $labelFor('return.status.', (string) ($return['status'] ?? '')) }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($returnable['eligible'] ?? false)
            <section class="panel">
                <h2>{{ t('return.title') }}</h2>
                @if (! empty($returnable['window_closes_at']))<p class="muted small">{{ t('return.until', ['date' => gmdate('j M Y', (int) strtotime((string) $returnable['window_closes_at']))]) }}</p>@endif
                <a class="btn" href="{{ route('orders.return', $routeParams) }}">{{ t('return.start') }}</a>
            </section>
        @endif
    </div>

    <aside class="summary-box" aria-labelledby="sum">
        <h2 id="sum">{{ t('cart.summary') }}</h2>
        <dl class="totals">
            @if (is_array($totals['subtotal'] ?? null))<div><dt>{{ t('totals.subtotal') }}</dt><dd>{{ money($totals['subtotal']) }}</dd></div>@endif
            @if (is_array($totals['discount'] ?? null) && ! \App\Support\Money::isZero($totals['discount']))<div><dt>{{ t('totals.discount') }}</dt><dd>− {{ money($totals['discount']) }}</dd></div>@endif
            @if (is_array($totals['shipping'] ?? null))<div><dt>{{ t('totals.shipping') }}</dt><dd>{{ money($totals['shipping']) }}</dd></div>@endif
            @if (is_array($totals['tax'] ?? null) && ! \App\Support\Money::isZero($totals['tax']))<div><dt>{{ t('totals.tax') }}</dt><dd>{{ money($totals['tax']) }}</dd></div>@endif
            <div class="grand"><dt>{{ t('totals.total') }}</dt><dd>{{ money(is_array($totals['grand_total'] ?? null) ? $totals['grand_total'] : null) }}</dd></div>
        </dl>
        <p class="muted small">{{ t('payment.'.$method) }}</p>
        <p class="muted small">{{ t('order.placed', ['date' => gmdate('j M Y, H:i', (int) strtotime((string) ($order['placed_at'] ?? 'now'))).' UTC']) }}</p>
    </aside>
</div>
@endsection
