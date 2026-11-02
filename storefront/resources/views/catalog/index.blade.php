@extends('layouts.app')

@use('App\Support\Money')

@php
    // The current query, minus paging, so "load more" and the sort keep every filter.
    $keep = static function (array $replace = []) use ($filters): array {
        $query = array_filter([
            'q' => $filters['q'] ?? '',
            'sort' => $filters['sort'] ?? '',
            'price_min' => $filters['price_min'] ?? '',
            'price_max' => $filters['price_max'] ?? '',
            'brand' => $filters['brand'] ?? '',
            'in_stock' => ($filters['in_stock'] ?? '') === 'true' ? '1' : '',
            'category' => ($filters['category'] ?? '') !== '' ? $filters['category'] : '',
            'attributes' => $filters['attributes'] ?? [],
        ], static fn ($v) => $v !== '' && $v !== []);

        foreach ($replace as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = $value;
            }
        }

        return $query;
    };

    $isCategoryPage = $categorySlug !== null;
    $isSearchPage = ! $isCategoryPage && ($filters['q'] ?? '') !== '';

    $baseUrl = static function (array $query = []) use ($isCategoryPage, $isSearchPage, $categorySlug): string {
        if ($isCategoryPage) {
            return route('categories.show', ['slug' => $categorySlug] + $query);
        }

        return route($isSearchPage ? 'search' : 'products.index', $query);
    };

    $keepWithoutCategory = $isCategoryPage
        ? static fn (array $r = []): array => $keep($r + ['category' => null])
        : $keep;

    $resetUrl = $isSearchPage
        ? route('search', ['q' => (string) $filters['q']])
        : $baseUrl();

    $sorts = ['' => 'catalog.sort.default', 'newest' => 'catalog.sort.newest', 'price_asc' => 'catalog.sort.price_asc', 'price_desc' => 'catalog.sort.price_desc', 'name' => 'catalog.sort.name', 'best_selling' => 'catalog.sort.best_selling'];
    $range = is_array($facets['price_range'] ?? null) ? $facets['price_range'] : null;
@endphp

@section('content')
<div class="page-head">
    <h1>{{ $title }}</h1>
</div>

<div class="layout-catalog">
    <aside class="filters" aria-label="{{ t('catalog.filters') }}">
        <details open>
            <summary>{{ t('catalog.filters') }}</summary>
            <form method="get" action="{{ $baseUrl() }}">
                @if (($filters['q'] ?? '') !== '')<input type="hidden" name="q" value="{{ $filters['q'] }}">@endif
                @if ($categorySlug === null && ($filters['category'] ?? '') !== '')<input type="hidden" name="category" value="{{ $filters['category'] }}">@endif

                <label for="sort">{{ t('catalog.sort') }}</label>
                <select id="sort" name="sort">
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}" {{ ($filters['sort'] ?? '') === (string) $value ? 'selected' : '' }}>{{ t($label) }}</option>
                    @endforeach
                </select>

                <fieldset>
                    <legend>{{ t('catalog.price') }}</legend>
                    <div class="two">
                        <input name="price_min" inputmode="decimal" aria-label="{{ t('catalog.price_min') }}" placeholder="{{ $range !== null ? Money::group((string) ($range['minimum']['amount'] ?? '')) : t('catalog.price_min') }}" value="{{ $filters['price_min'] ?? '' }}">
                        <input name="price_max" inputmode="decimal" aria-label="{{ t('catalog.price_max') }}" placeholder="{{ $range !== null ? Money::group((string) ($range['maximum']['amount'] ?? '')) : t('catalog.price_max') }}" value="{{ $filters['price_max'] ?? '' }}">
                    </div>
                </fieldset>

                @if (! empty($facets['brands']))
                    <label for="brand">{{ t('catalog.brand') }}</label>
                    <select id="brand" name="brand">
                        <option value="">{{ t('catalog.any') }}</option>
                        @foreach ($facets['brands'] as $brand)
                            <option value="{{ $brand['slug'] ?? '' }}" {{ ($filters['brand'] ?? '') === ($brand['slug'] ?? null) ? 'selected' : '' }}>
                                {{ $brand['name'] ?? '' }}{{ isset($brand['count']) ? ' ('.num((string) $brand['count']).')' : '' }}
                            </option>
                        @endforeach
                    </select>
                @endif

                @foreach ((array) ($facets['attributes'] ?? []) as $attribute)
                    @php $slug = (string) ($attribute['slug'] ?? ''); @endphp
                    @continue($slug === '')
                    <label for="attr-{{ $slug }}">{{ $attribute['name'] ?? $slug }}</label>
                    <select id="attr-{{ $slug }}" name="attributes[{{ $slug }}]">
                        <option value="">{{ t('catalog.any') }}</option>
                        @foreach ((array) ($attribute['values'] ?? []) as $value)
                            @php $label = (string) (is_array($value) ? ($value['value'] ?? $value['name'] ?? '') : $value); @endphp
                            <option value="{{ $label }}" {{ (($filters['attributes'][$slug] ?? '') === $label) ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                @endforeach

                <label class="check"><input type="checkbox" name="in_stock" value="1" {{ ($filters['in_stock'] ?? '') === 'true' ? 'checked' : '' }}> {{ t('catalog.in_stock_only') }}</label>

                <div class="actions">
                    <button class="btn" type="submit">{{ t('catalog.apply') }}</button>
                    <a class="btn btn-quiet" href="{{ $resetUrl }}">{{ t('catalog.reset') }}</a>
                </div>
            </form>
        </details>
    </aside>

    <section aria-live="polite">
        @if ($error !== null)
            <p class="flash flash-error" role="alert">{{ t($error) }}</p>
        @elseif ($products === [])
            <div class="empty">
                <p>{{ t('catalog.no_results') }}</p>
                <a class="btn" href="{{ route('products.index') }}">{{ t('catalog.browse_all') }}</a>
            </div>
        @else
            <div class="grid">
                @foreach ($products as $product)@include('partials.product_card', ['product' => $product])@endforeach
            </div>

            <nav class="pager" aria-label="{{ t('catalog.pagination') }}">
                @if ($hasCursor)
                    <a class="btn btn-quiet" href="{{ $baseUrl($keepWithoutCategory()) }}">{{ t('catalog.first_page') }}</a>
                @endif
                @if ($hasMore && $nextCursor !== null)
                    <a class="btn" rel="next" href="{{ $baseUrl($keepWithoutCategory(['cursor' => $nextCursor])) }}">{{ t('catalog.next') }}</a>
                @endif
            </nav>
        @endif
    </section>
</div>
@endsection
