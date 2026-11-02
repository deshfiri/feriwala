@extends('layouts.app')

@section('content')
@php
    $lines = array_values(array_filter((array) ($returnable['lines'] ?? []), static fn ($l) => is_array($l) && (int) ($l['returnable'] ?? 0) > 0));
    $names = [];

    foreach ((array) ($order['items'] ?? []) as $item) {
        if (is_array($item) && isset($item['sku'])) {
            $names[(string) $item['sku']] = (string) ($item['name'] ?? $item['sku']);
        }
    }

    $reasons = ['damaged', 'wrong_item', 'not_as_described', 'missing_parts', 'changed_mind', 'other'];
    $routeParams = ['id' => (string) $local['public_id'], 't' => (string) $local['access_token']];
@endphp
<div class="page-head"><h1>{{ t('return.title') }}</h1></div>

@if ($errors->has('form'))<p class="flash flash-error" role="alert">{{ $errors->first('form') }}</p>@endif

<form class="checkout" action="{{ route('orders.return.submit', $routeParams) }}" method="post">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ $idempotency }}">

    <fieldset>
        <legend>{{ t('return.what') }}</legend>
        @foreach ($lines as $line)
            @php
                $sku = (string) $line['sku'];
            @endphp
            <div class="return-line">
                <label for="qty-{{ $sku }}">{{ $names[$sku] ?? $sku }}</label>
                <input id="qty-{{ $sku }}" name="qty[{{ $sku }}]" type="number" min="0" max="{{ (string) (int) $line['returnable'] }}" value="0">
                <span class="muted small">{{ t('return.up_to', ['n' => num((int) $line['returnable'])]) }}</span>
            </div>
        @endforeach
    </fieldset>

    <fieldset>
        <legend>{{ t('return.why') }}</legend>
        <label for="reason">{{ t('return.reason') }}</label>
        <select id="reason" name="reason" required>
            <option value="">{{ t('checkout.choose') }}</option>
            @foreach ($reasons as $reason)<option value="{{ $reason }}">{{ t('return.reason.'.$reason) }}</option>@endforeach
        </select>

        <label for="note">{{ t('return.note') }} <span class="muted">{{ t('checkout.optional') }}</span></label>
        <textarea id="note" name="note" rows="4" maxlength="1000"></textarea>
    </fieldset>

    <div class="actions">
        <a class="btn btn-quiet" href="{{ route('orders.show', $routeParams) }}">{{ t('return.back') }}</a>
        <button class="btn btn-accent" type="submit" data-once>{{ t('return.submit') }}</button>
    </div>
</form>
@endsection
