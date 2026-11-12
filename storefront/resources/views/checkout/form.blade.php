@extends('layouts.app')

@use('App\Support\Money')

@section('content')
@php
    $chosen = old('payment_method', (string) ($methods[0] ?? ''));
    $unitCount = array_sum(array_column($items, 'quantity'));
    $hasQuote = $quote !== null && $quote['grand_total'] !== null;
    $hasOnline = in_array('online', $methods, true);

    /** Field error: the server's sentence, kept beside the field it belongs to. */
    $invalid = static fn (string $field): string => $errors->has($field) ? ' aria-invalid="true" aria-describedby="err-'.$field.'"' : '';
@endphp

{{-- Icons, drawn once and referenced by the cards below. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="co-i-user" viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="co-i-pin" viewBox="0 0 24 24"><path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.3" fill="none" stroke="currentColor" stroke-width="1.7"/></symbol>
    <symbol id="co-i-card" viewBox="0 0 24 24"><rect x="3" y="5.5" width="18" height="13" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M3 10h18M7 15h3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
    <symbol id="co-i-cash" viewBox="0 0 24 24"><rect x="3" y="6.5" width="18" height="11" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.4" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M6.5 12h.01M17.5 12h.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></symbol>
    <symbol id="co-i-truck" viewBox="0 0 24 24"><path d="M3 6.5h11v9H3zM14 9.5h4l3 3v3h-7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><circle cx="7.5" cy="17.5" r="1.8" fill="var(--surface)" stroke="currentColor" stroke-width="1.7"/><circle cx="17" cy="17.5" r="1.8" fill="var(--surface)" stroke="currentColor" stroke-width="1.7"/></symbol>
    <symbol id="co-i-lock" viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
    <symbol id="co-i-check" viewBox="0 0 24 24"><path d="m5 12.5 4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="co-i-chevron" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="co-i-bag" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6L5 8Zm4 0V6.5a3 3 0 0 1 6 0V8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/></symbol>
</svg>

<div class="co">
    <div class="page-head"><h1>{{ t('checkout.title') }}</h1></div>
    @include('partials.steps', ['current' => 2])

    <div class="co-grid">
        {{-- ============================================================ form --}}
        <form id="checkout-form" class="co-form" action="{{ route('checkout.submit') }}" method="post" novalidate data-once data-checkout>
            @csrf

            <p class="co-required-note">{{ t('checkout.required_note') }}</p>

            {{-- 1 · Contact --}}
            <fieldset class="co-card">
                <legend>
                    <span class="co-card-icon"><svg class="co-ico" aria-hidden="true"><use href="#co-i-user"/></svg></span>
                    <span class="co-card-title">{{ t('checkout.contact') }}<small>{{ t('checkout.contact_help') }}</small></span>
                </legend>

                <div class="co-field">
                    <label for="name">{{ t('checkout.name') }} <span class="co-req" aria-hidden="true">*</span></label>
                    <input id="name" name="name" autocomplete="name" required maxlength="160" value="{{ old('name') }}" data-msg="{{ t('checkout.error.name') }}"{!! $invalid('name') !!}>
                    @if ($errors->has('name'))<p class="co-error" id="err-name" role="alert">{{ $errors->first('name') }}</p>@endif
                </div>

                <div class="co-row">
                    <div class="co-field">
                        <label for="phone">{{ t('checkout.phone') }} <span class="co-req" aria-hidden="true">*</span></label>
                        <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="32" placeholder="01XXXXXXXXX" value="{{ old('phone') }}" data-msg="{{ t('checkout.error.phone') }}"{!! $invalid('phone') !!}>
                        @if ($errors->has('phone'))<p class="co-error" id="err-phone" role="alert">{{ $errors->first('phone') }}</p>@endif
                    </div>
                    <div class="co-field">
                        <label for="email">{{ t('checkout.email') }} <span class="co-opt">{{ t('checkout.optional') }}</span></label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" data-msg="{{ t('checkout.error.email') }}"{!! $invalid('email') !!}>
                        @if ($errors->has('email'))<p class="co-error" id="err-email" role="alert">{{ $errors->first('email') }}</p>@endif
                    </div>
                </div>
            </fieldset>

            {{-- 2 · Delivery --}}
            <fieldset class="co-card">
                <legend>
                    <span class="co-card-icon"><svg class="co-ico" aria-hidden="true"><use href="#co-i-pin"/></svg></span>
                    <span class="co-card-title">{{ t('checkout.delivery') }}</span>
                </legend>

                <div class="co-areas" data-areas hidden>
                    <p class="co-help">{{ t('checkout.area_help') }}</p>
                    <div class="co-row co-row-3">
                        <div class="co-field co-select">
                            <label for="area-division">{{ t('checkout.division') }}</label>
                            <select id="area-division" data-level="0"><option value="">{{ t('checkout.choose') }}</option></select>
                        </div>
                        <div class="co-field co-select">
                            <label for="area-district">{{ t('checkout.district') }}</label>
                            <select id="area-district" data-level="1" disabled><option value="">{{ t('checkout.choose') }}</option></select>
                        </div>
                        <div class="co-field co-select">
                            <label for="area-upazila">{{ t('checkout.upazila') }}</label>
                            <select id="area-upazila" data-level="2" disabled><option value="">{{ t('checkout.choose') }}</option></select>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="area_id" id="area_id" value="{{ old('area_id') }}">

                <div class="co-field">
                    <label for="line1">{{ t('checkout.address') }} <span class="co-req" aria-hidden="true">*</span></label>
                    <input id="line1" name="line1" autocomplete="address-line1" required maxlength="255" value="{{ old('line1') }}" data-msg="{{ t('checkout.error.address') }}"{!! $invalid('line1') !!}>
                    @if ($errors->has('line1'))<p class="co-error" id="err-line1" role="alert">{{ $errors->first('line1') }}</p>@endif
                </div>

                <div class="co-field">
                    <label for="line2">{{ t('checkout.address2') }} <span class="co-opt">{{ t('checkout.optional') }}</span></label>
                    <input id="line2" name="line2" autocomplete="address-line2" maxlength="255" value="{{ old('line2') }}">
                </div>

                <div class="co-row co-row-3">
                    <div class="co-field">
                        <label for="city">{{ t('checkout.city') }} <span class="co-req" aria-hidden="true">*</span></label>
                        <input id="city" name="city" autocomplete="address-level2" required maxlength="120" value="{{ old('city') }}" data-msg="{{ t('checkout.error.city') }}"{!! $invalid('city') !!}>
                        @if ($errors->has('city'))<p class="co-error" id="err-city" role="alert">{{ $errors->first('city') }}</p>@endif
                    </div>
                    <div class="co-field">
                        <label for="district">{{ t('checkout.district_text') }}</label>
                        <input id="district" name="district" autocomplete="address-level1" maxlength="120" value="{{ old('district') }}">
                    </div>
                    <div class="co-field">
                        <label for="postcode">{{ t('checkout.postcode') }} <span class="co-opt">{{ t('checkout.optional') }}</span></label>
                        <input id="postcode" name="postcode" autocomplete="postal-code" inputmode="numeric" maxlength="20" value="{{ old('postcode') }}">
                    </div>
                </div>

                @if ($hasQuote)
                    <p class="co-note">
                        <svg class="co-ico" aria-hidden="true"><use href="#co-i-truck"/></svg>
                        <span>{{ Money::isZero($quote['shipping']) ? t('checkout.delivery_free') : t('checkout.delivery_flat', ['amount' => money($quote['shipping'])]) }}</span>
                    </p>
                @endif

                <div class="co-field">
                    <label for="note">{{ t('checkout.note') }} <span class="co-opt">{{ t('checkout.optional') }}</span></label>
                    <textarea id="note" name="note" rows="3" maxlength="1000">{{ old('note') }}</textarea>
                </div>
            </fieldset>

            {{-- 3 · Payment --}}
            <fieldset class="co-card">
                <legend>
                    <span class="co-card-icon"><svg class="co-ico" aria-hidden="true"><use href="#co-i-card"/></svg></span>
                    <span class="co-card-title">{{ t('checkout.payment') }}<small>{{ t('checkout.payment_help') }}</small></span>
                </legend>

                @if ($methods === [])
                    <p class="co-error" role="alert">{{ t('checkout.no_methods') }}</p>
                @endif

                @foreach ($methods as $method)
                    <label class="co-option">
                        <input type="radio" name="payment_method" value="{{ $method }}" {{ $chosen === $method ? 'checked' : '' }}>
                        <span class="co-option-icon"><svg class="co-ico" aria-hidden="true"><use href="#{{ $method === 'cod' ? 'co-i-cash' : 'co-i-card' }}"/></svg></span>
                        <span class="co-option-text">
                            <strong>{{ t('payment.'.$method) }}</strong>
                            <small>{{ t('payment.'.$method.'_help') }}</small>
                        </span>
                        <span class="co-option-tick" aria-hidden="true"><svg class="co-ico"><use href="#co-i-check"/></svg></span>
                    </label>

                    @if ($method === 'online' && count($gateways) > 1)
                        <div class="co-gateways" data-gateways{{ $chosen === 'online' ? '' : ' hidden' }}>
                            @php
                                $chosenGateway = old('gateway', (string) $gateways[0]['name']);
                            @endphp
                            @foreach ($gateways as $gateway)
                                <label class="co-option co-option-small">
                                    <input type="radio" name="gateway" value="{{ $gateway['name'] }}" {{ $chosenGateway === $gateway['name'] ? 'checked' : '' }}>
                                    <span class="co-option-text">@include('partials.gateway', ['gateway' => $gateway])</span>
                                    <span class="co-option-tick" aria-hidden="true"><svg class="co-ico"><use href="#co-i-check"/></svg></span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                @endforeach

                @if ($errors->has('payment_method'))<p class="co-error" id="err-payment_method" role="alert">{{ $errors->first('payment_method') }}</p>@endif
                @if ($errors->has('gateway'))<p class="co-error" role="alert">{{ $errors->first('gateway') }}</p>@endif
            </fieldset>

            <div class="co-back">
                <a class="co-link" href="{{ route('cart.show') }}">← {{ t('checkout.back') }}</a>
            </div>
        </form>

        {{-- ======================================================== summary --}}
        <aside class="co-summary" aria-labelledby="co-sum-title">
            <details class="co-sum-box" open data-summary>
                <summary>
                    <span class="co-sum-head">
                        <svg class="co-ico" aria-hidden="true"><use href="#co-i-bag"/></svg>
                        <span>
                            <span class="co-sum-title" id="co-sum-title">{{ t('cart.summary') }}</span>
                            <span class="co-sum-count">{{ t('checkout.items_count', ['n' => (string) $unitCount]) }}</span>
                        </span>
                    </span>
                    <span class="co-sum-total">
                        @if ($hasQuote)<strong>{{ money($quote['grand_total']) }}</strong>@endif
                        <svg class="co-ico co-chevron" aria-hidden="true"><use href="#co-i-chevron"/></svg>
                    </span>
                </summary>

                <ul class="co-lines">
                    @foreach ($items as $line)
                        @php $price = $prices[$line['sku']] ?? null; @endphp
                        <li>
                            <span class="co-thumb">
                                @if (safe_url($line['image']) !== '')<img src="{{ safe_url($line['image']) }}" alt="" width="64" height="64" loading="lazy">@else<span class="co-thumb-blank">{{ mb_substr($line['name'], 0, 1) }}</span>@endif
                                <span class="co-qty">{{ num((string) $line['quantity']) }}</span>
                            </span>
                            <span class="co-line-body">
                                <span class="co-line-name">{{ $line['name'] }}</span>
                                @if ($line['variant'] !== null)<span class="co-line-meta">{{ $line['variant'] }}</span>@endif
                                @if ($price !== null && $price['unit_price'] !== null)<span class="co-line-meta">{{ num((string) $line['quantity']) }} × {{ money($price['unit_price']) }}</span>@endif
                            </span>
                            @if ($price !== null && $price['line_total'] !== null)<span class="co-line-price">{{ money($price['line_total']) }}</span>@endif
                        </li>
                    @endforeach
                </ul>

                @if ($hasQuote)
                    <dl class="co-totals">
                        <div><dt>{{ t('totals.subtotal') }}</dt><dd>{{ money($quote['subtotal']) }}</dd></div>
                        @if (! Money::isZero($quote['discount']))
                            <div class="co-discount"><dt>{{ t('totals.discount') }}</dt><dd>− {{ money($quote['discount']) }}</dd></div>
                        @endif
                        <div><dt>{{ t('totals.shipping') }}</dt><dd>{{ Money::isZero($quote['shipping']) ? t('checkout.delivery_free') : money($quote['shipping']) }}</dd></div>
                        @if (! Money::isZero($quote['tax']))
                            <div><dt>{{ t('totals.tax') }}</dt><dd>{{ money($quote['tax']) }}</dd></div>
                        @endif
                        <div class="co-grand"><dt>{{ t('totals.total') }}</dt><dd>{{ money($quote['grand_total']) }}</dd></div>
                    </dl>
                @else
                    <p class="co-help">{{ t('checkout.price_unavailable') }}</p>
                @endif

                <div class="co-submit">
                    <button class="co-btn" type="submit" form="checkout-form" {{ $methods === [] ? 'disabled' : '' }} data-submit data-busy="{{ t('checkout.submitting') }}">
                        <span class="co-btn-label">{{ t('checkout.review') }}</span>
                        <svg class="co-ico co-btn-arrow" aria-hidden="true"><use href="#co-i-chevron"/></svg>
                    </button>
                    <p class="co-help co-center">{{ t('checkout.continue_hint') }}</p>
                </div>

                <ul class="co-trust">
                    <li><svg class="co-ico" aria-hidden="true"><use href="#co-i-lock"/></svg><span>{{ t('checkout.trust_review') }}</span></li>
                    @if ($hasQuote)<li><svg class="co-ico" aria-hidden="true"><use href="#co-i-check"/></svg><span>{{ t('cart.price_note') }}</span></li>@endif
                    @if ($hasOnline)<li><svg class="co-ico" aria-hidden="true"><use href="#co-i-card"/></svg><span>{{ t('checkout.trust_pay') }}</span></li>@endif
                </ul>
            </details>
        </aside>
    </div>

    {{-- Phone: the total and the button stay in reach while the form scrolls. --}}
    <div class="co-bar">
        <div class="co-bar-total">
            <span>{{ t('totals.total') }}</span>
            @if ($hasQuote)<strong>{{ money($quote['grand_total']) }}</strong>@endif
        </div>
        <button class="co-btn" type="submit" form="checkout-form" {{ $methods === [] ? 'disabled' : '' }} data-submit data-busy="{{ t('checkout.submitting') }}">
            <span class="co-btn-label">{{ t('checkout.review') }}</span>
        </button>
    </div>
</div>
@endsection
