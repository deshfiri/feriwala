{{--
    The ERP's totals for a basket, as the cart and the checkout show them. Also
    rendered on its own when the page asks for fresh figures (a quantity or an
    area changed), so a live update looks exactly like a page load.

    $quote    the normalised quote (Checkouts::normaliseQuote)
    $variant  'cart' or 'checkout': the class names, and what an unpriced
              delivery says (at checkout: choose your district).
--}}
@use('App\Support\Money')
@php
    $checkoutView = ($variant ?? 'cart') === 'checkout';
    $delivery = $quote['delivery'] ?? ['status' => 'address_required', 'area' => null, 'is_free' => false];
    $areaLabel = $delivery['area'] !== null ? t('delivery.'.$delivery['area']) : null;
    $priced = $quote['shipping'] !== null;
@endphp
<dl class="{{ $checkoutView ? 'co-totals' : 'totals' }}">
    <div><dt>{{ t('totals.subtotal') }}</dt><dd>{{ money($quote['subtotal']) }}</dd></div>
    @if (! Money::isZero($quote['discount']))
        <div class="{{ $checkoutView ? 'co-discount' : '' }}"><dt>{{ t('totals.discount') }}</dt><dd>− {{ money($quote['discount']) }}</dd></div>
    @endif
    <div data-delivery-line>
        <dt>{{ t('totals.shipping') }}@if ($areaLabel !== null) <span class="muted small">({{ $areaLabel }})</span>@endif</dt>
        <dd>
            @if (! $priced)
                <span class="muted small">{{ $checkoutView ? t('delivery.choose_area') : t('delivery.at_checkout') }}</span>
            @elseif ($delivery['is_free'])
                {{ t('delivery.free') }}
            @else
                {{ money($quote['shipping']) }}
            @endif
        </dd>
    </div>
    @if (! Money::isZero($quote['tax']))
        <div><dt>{{ t('totals.tax') }}</dt><dd>{{ money($quote['tax']) }}</dd></div>
    @endif
    @if ($quote['grand_total'] !== null)
        <div class="{{ $checkoutView ? 'co-grand' : 'grand' }}"><dt>{{ t('totals.total') }}</dt><dd>{{ money($quote['grand_total']) }}</dd></div>
    @endif
</dl>
