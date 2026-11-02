@extends('layouts.app')

@use('App\Support\Money')

@php
    $blocked = $lineProblems !== [] || $problem === 'cart.not_accepting';
@endphp

@section('content')
<div class="page-head"><h1>{{ t('cart.title') }}</h1></div>
@include('partials.steps', ['current' => 1])

@if ($items === [])
    <div class="empty">
        <svg viewBox="0 0 24 24" width="44" height="44" aria-hidden="true" focusable="false"><path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.76L20 8H6.2" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.3" fill="currentColor"/><circle cx="17" cy="20" r="1.3" fill="currentColor"/></svg>
        <p><strong>{{ t('cart.empty') }}</strong></p>
        <p class="muted">{{ t('cart.empty_hint') }}</p>
        <a class="btn" href="{{ route('products.index') }}">{{ t('cart.continue') }}</a>
    </div>
@else
    @if ($problem !== null)<p class="flash flash-warning" role="status">{{ t($problem) }}</p>@endif

    <div class="layout-cart">
        <ul class="lines">
            @foreach ($items as $line)
                @php $price = $prices[$line['sku']] ?? null; @endphp
                <li class="line">
                    <a class="line-media" href="{{ route('products.show', ['slug' => $line['slug']]) }}">
                        @if (safe_url($line['image']) !== '')<img src="{{ safe_url($line['image']) }}" alt="" width="96" height="96" loading="lazy">@else<span class="placeholder">{{ mb_substr($line['name'], 0, 1) }}</span>@endif
                    </a>
                    <div class="line-body">
                        <a class="line-name" href="{{ route('products.show', ['slug' => $line['slug']]) }}">{{ $line['name'] }}</a>
                        @if ($line['variant'] !== null)<p class="muted small">{{ $line['variant'] }}</p>@endif
                        @if ($price !== null && $price['unit_price'] !== null)<p class="muted small">{{ money($price['unit_price']) }}</p>@endif
                        @if (isset($lineProblems[$line['sku']]))<p class="error small" role="alert">{{ t($lineProblems[$line['sku']]) }}</p>@endif

                        <div class="line-actions">
                            <form action="{{ route('cart.update') }}" method="post" class="qty">
                                @csrf
                                <input type="hidden" name="sku" value="{{ $line['sku'] }}">
                                <label class="sr-only" for="q-{{ $line['sku'] }}">{{ t('product.quantity') }}</label>
                                <input id="q-{{ $line['sku'] }}" name="quantity" type="number" min="1" max="1000" value="{{ (string) $line['quantity'] }}">
                                <button type="submit" class="btn btn-quiet btn-small">{{ t('cart.update') }}</button>
                            </form>
                            <form action="{{ route('cart.remove') }}" method="post">
                                @csrf
                                <input type="hidden" name="sku" value="{{ $line['sku'] }}">
                                <button type="submit" class="link danger">{{ t('cart.remove') }}</button>
                            </form>
                        </div>
                    </div>
                    <div class="line-total">
                        @if ($price !== null && $price['line_total'] !== null)<strong>{{ money($price['line_total']) }}</strong>@endif
                    </div>
                </li>
            @endforeach
        </ul>

        <aside class="summary-box" aria-labelledby="sum">
            <h2 id="sum">{{ t('cart.summary') }}</h2>
            @if ($quote !== null)
                <dl class="totals">
                    <div><dt>{{ t('totals.subtotal') }}</dt><dd>{{ money($quote['subtotal']) }}</dd></div>
                    @if (! Money::isZero($quote['discount']))
                        <div><dt>{{ t('totals.discount') }}</dt><dd>− {{ money($quote['discount']) }}</dd></div>
                    @endif
                    <div><dt>{{ t('totals.shipping') }}</dt><dd>{{ money($quote['shipping']) }}</dd></div>
                    @if (! Money::isZero($quote['tax']))
                        <div><dt>{{ t('totals.tax') }}</dt><dd>{{ money($quote['tax']) }}</dd></div>
                    @endif
                    <div class="grand"><dt>{{ t('totals.total') }}</dt><dd>{{ money($quote['grand_total']) }}</dd></div>
                </dl>
                <p class="muted small">{{ t('cart.price_note') }}</p>
            @else
                <p class="muted">{{ t('cart.no_quote') }}</p>
            @endif

            @if ($blocked)
                <button class="btn btn-wide" disabled>{{ t('cart.checkout') }}</button>
            @else
                <a class="btn btn-accent btn-wide" href="{{ route('checkout.form') }}">{{ t('cart.checkout') }}</a>
            @endif
            <a class="btn btn-quiet btn-wide" href="{{ route('products.index') }}">{{ t('cart.continue') }}</a>
        </aside>
    </div>
@endif
@endsection
