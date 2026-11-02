@extends('layouts.app')

@use('App\Services\Catalog')

@php
    $variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));
    $media = array_values(array_filter(
        (array) ($product['media'] ?? []),
        static fn ($m) => is_array($m) && ($m['type'] ?? 'image') === 'image' && safe_url($m['url'] ?? null) !== '',
    ));
    usort($media, static fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

    $min = max(1, (int) ($product['min_order_quantity'] ?? 1));
    $max = (int) ($product['max_order_quantity'] ?? 0);

    $firstInStock = null;
    foreach ($variants as $variant) {
        if ($variant['availability']['in_stock'] ?? false) {
            $firstInStock = $variant;
            break;
        }
    }
    $selected = $firstInStock ?? ($variants[0] ?? null);
    $anyStock = $firstInStock !== null;
    $selectedQty = (int) ($selected['availability']['quantity'] ?? 0);
    $low = Catalog::LOW_STOCK;
    $brand = is_array($product['brand'] ?? null) ? $product['brand'] : null;
    $productCategories = array_values(array_filter((array) ($product['categories'] ?? []), 'is_array'));
    $paras = paragraphs((string) ($product['description'] ?? ''));
@endphp

@section('content')
<nav class="crumbs" aria-label="{{ t('product.breadcrumb') }}">
    <a href="{{ route('home') }}">{{ t('nav.home') }}</a> ›
    <a href="{{ route('products.index') }}">{{ t('nav.all') }}</a>
    @if ($productCategories !== []) › <a href="{{ route('categories.show', ['slug' => (string) ($productCategories[0]['slug'] ?? '')]) }}">{{ $productCategories[0]['name'] ?? '' }}</a>@endif
</nav>

<div class="product">
    <div class="gallery">
        @if ($media !== [])
            <img id="hero-image" class="hero-image" src="{{ safe_url($media[0]['url']) }}" alt="{{ $media[0]['alt'] ?? $product['name'] ?? '' }}" width="800" height="800" data-fallback="hero-fallback">
            <div id="hero-fallback" class="hero-image placeholder" role="img" aria-label="{{ t('ui.image_missing') }}" hidden><span>{{ mb_substr((string) ($product['name'] ?? ''), 0, 1) }}</span></div>
            @if (count($media) > 1)
                <ul class="thumbs">
                    @foreach ($media as $index => $item)
                        <li>
                            <a href="{{ safe_url($item['url']) }}" data-gallery="{{ safe_url($item['url']) }}" data-alt="{{ $item['alt'] ?? '' }}" {!! $index === 0 ? 'aria-current="true"' : '' !!}>
                                <img src="{{ safe_url($item['url']) }}" alt="{{ $item['alt'] ?? t('product.photo', ['n' => (string) ($index + 1)]) }}" loading="lazy" width="96" height="96">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        @else
            <div class="hero-image placeholder" role="img" aria-label="{{ t('ui.image_missing') }}"><span>{{ mb_substr((string) ($product['name'] ?? ''), 0, 1) }}</span></div>
        @endif
    </div>

    <div class="summary">
        @if ($brand !== null && ! empty($brand['name']))<p class="muted">{{ $brand['name'] }}</p>@endif
        <h1>{{ $product['name'] ?? '' }}</h1>

        @if ($selected !== null)
            <p class="price big" id="price" aria-live="polite">
                <strong>{{ money($selected['price'] ?? null) }}</strong>
                @if (is_array($selected['compare_at_price'] ?? null))<s class="muted">{{ money($selected['compare_at_price']) }}</s>@endif
            </p>
        @endif

        @if (! empty($product['short_description']))
            <p class="lead">{{ plain_text((string) $product['short_description']) }}</p>
        @endif

        @if ($variants === [])
            <p class="flash flash-warning">{{ t('product.unavailable') }}</p>
        @else
            <form class="buy" action="{{ route('cart.add') }}" method="post">
                @csrf
                <input type="hidden" name="product" value="{{ $product['slug'] ?? '' }}">

                @if (count($variants) > 1)
                    <label for="sku">{{ t('product.option') }}</label>
                    <select id="sku" name="sku" data-variants>
                        @foreach ($variants as $variant)
                            @php
                                $in = (bool) ($variant['availability']['in_stock'] ?? false);
                                $label = \App\Http\Controllers\CartController::variantLabel($variant) ?? (string) ($variant['sku'] ?? '');
                            @endphp
                            <option value="{{ $variant['sku'] ?? '' }}"
                                    data-price="{{ money($variant['price'] ?? null) }}"
                                    data-compare="{{ money(is_array($variant['compare_at_price'] ?? null) ? $variant['compare_at_price'] : null) }}"
                                    data-stock="{{ $in ? '1' : '0' }}"
                                    data-qty="{{ (string) (int) ($variant['availability']['quantity'] ?? 0) }}"
                                    {{ ($selected['sku'] ?? null) === ($variant['sku'] ?? null) ? 'selected' : '' }}>
                                {{ $label }}{{ $in ? '' : ' — '.t('product.out_of_stock') }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="sku" value="{{ $variants[0]['sku'] ?? '' }}">
                @endif

                <p id="stock" class="stock {{ ! $anyStock ? 'out' : ($selectedQty > 0 && $selectedQty <= $low ? 'low' : 'in') }}" aria-live="polite"
                   data-in="{{ t('product.in_stock') }}" data-out="{{ t('product.out_of_stock') }}" data-low="{{ t('product.low_stock', ['n' => ':n']) }}" data-threshold="{{ (string) $low }}">
                    @if (! $anyStock){{ t('product.out_of_stock') }}
                    @elseif ($selectedQty > 0 && $selectedQty <= $low){{ t('product.low_stock', ['n' => num((string) $selectedQty)]) }}
                    @else{{ t('product.in_stock') }}@endif
                </p>

                <label for="quantity">{{ t('product.quantity') }}</label>
                <div class="stepper" data-stepper>
                    <button type="button" class="step" data-step="-1" aria-label="{{ t('product.qty_less') }}" hidden>−</button>
                    <input id="quantity" name="quantity" type="number" inputmode="numeric" min="{{ (string) $min }}" {!! $max > 0 ? 'max="'.e((string) $max).'"' : '' !!} data-max="{{ (string) $max }}" value="{{ (string) $min }}">
                    <button type="button" class="step" data-step="1" aria-label="{{ t('product.qty_more') }}" hidden>+</button>
                </div>
                @if ($min > 1 || $max > 0)
                    <p class="muted small">{{ t('product.quantity_range', ['min' => (string) $min, 'max' => $max > 0 ? (string) $max : '∞']) }}</p>
                @endif

                <button class="btn btn-accent btn-wide btn-large" type="submit" id="add" {{ $anyStock ? '' : 'disabled' }}>{{ t('product.add') }}</button>
            </form>
        @endif

        @if (! empty($product['sku']))<p class="muted small">{{ t('product.code') }}: {{ $product['sku'] }}</p>@endif
    </div>
</div>

@if ($paras !== [])
    <section class="description" aria-labelledby="desc">
        <h2 id="desc">{{ t('product.description') }}</h2>
        @foreach ($paras as $paragraph)<p>{{ $paragraph }}</p>@endforeach
    </section>
@endif
@endsection
