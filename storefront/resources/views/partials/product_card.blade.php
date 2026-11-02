@php
    /** $product: array<string, mixed> */
    $cardImage = safe_url(\App\Services\Catalog::primaryImage($product));
    $price = \App\Services\Catalog::startingPrice($product);
    $compare = null;
    $variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));

    foreach ($variants as $variant) {
        if (is_array($variant['compare_at_price'] ?? null)) {
            $compare = $variant['compare_at_price'];
            break;
        }
    }

    $inStock = \App\Services\Catalog::inStock($product);
    $left = \App\Services\Catalog::unitsLeft($product);
    $slug = (string) ($product['slug'] ?? '');
    $name = (string) ($product['name'] ?? '');
    $quickAdd = $inStock && count($variants) === 1 && ($variants[0]['sku'] ?? '') !== '';
    $min = max(1, (int) ($product['min_order_quantity'] ?? 1));
@endphp
<article class="card{{ $inStock ? '' : ' card-sold-out' }}">
    <a class="card-media" href="{{ route('products.show', ['slug' => $slug]) }}" tabindex="-1" aria-hidden="true">
        @if ($cardImage !== '')
            <img src="{{ $cardImage }}" alt="" loading="lazy" width="400" height="500" data-fallback="card-fallback">
        @endif
        <span class="placeholder card-fallback"{!! $cardImage !== '' ? ' hidden' : '' !!}>{{ mb_substr($name, 0, 1) }}</span>
        @if (! $inStock)
            <span class="tag tag-muted">{{ t('product.out_of_stock') }}</span>
        @elseif ($compare !== null)
            <span class="tag">{{ t('product.sale') }}</span>
        @endif
    </a>
    <div class="card-body">
        @if (! empty($product['brand']['name']))<p class="card-brand">{{ $product['brand']['name'] }}</p>@endif
        <h3 class="card-title"><a href="{{ route('products.show', ['slug' => $slug]) }}">{{ $name }}</a></h3>
        @if (count($variants) > 1)<p class="card-meta">{{ t('product.options_count', ['n' => num((string) count($variants))]) }}</p>@endif

        @if ($price !== null)
            <p class="price">
                @if (count($variants) > 1)<span class="muted small">{{ t('product.from') }}</span>@endif
                <strong>{{ money($price) }}</strong>
                @if ($compare !== null)<s class="muted small">{{ money($compare) }}</s>@endif
            </p>
        @endif

        @if ($inStock && $left > 0 && $left <= \App\Services\Catalog::LOW_STOCK)
            <p class="stock-line low">{{ t('product.low_stock', ['n' => num((string) $left)]) }}</p>
        @elseif (! $inStock)
            <p class="stock-line out">{{ t('product.out_of_stock') }}</p>
        @endif

        <div class="card-actions">
            @if ($quickAdd)
                <form action="{{ route('cart.add') }}" method="post" data-once>
                    @csrf
                    <input type="hidden" name="product" value="{{ $product['slug'] ?? '' }}">
                    <input type="hidden" name="sku" value="{{ $variants[0]['sku'] }}">
                    <input type="hidden" name="quantity" value="{{ (string) $min }}">
                    <button class="btn btn-small btn-wide" type="submit">{{ t('product.quick_add') }}</button>
                </form>
            @else
                <a class="btn btn-small btn-quiet btn-wide" href="{{ route('products.show', ['slug' => $slug]) }}">{{ t($inStock ? 'product.view_options' : 'product.out_of_stock') }}</a>
            @endif
        </div>
    </div>
</article>
