@extends('layouts.app')

@use('App\Support\Money')

@php

    // The current query, minus paging, so "load more" and sort keep every filter.

    $keep = static function (array $replace = []) use ($filters): array {
        $query = array_filter(
            [
                'q' => $filters['q'] ?? '',
                'sort' => $filters['sort'] ?? '',
                'price_min' => $filters['price_min'] ?? '',
                'price_max' => $filters['price_max'] ?? '',
                'brand' => $filters['brand'] ?? '',
                'in_stock' => ($filters['in_stock'] ?? '') === 'true' ? '1' : '',
                'category' => ($filters['category'] ?? '') !== '' ? $filters['category'] : '',
                'attributes' => $filters['attributes'] ?? [],
            ],
            static fn($v) => $v !== '' && $v !== [],
        );

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
    $isSearchPage = !$isCategoryPage && ($filters['q'] ?? '') !== '';

    $baseUrl = static function (array $query = []) use ($isCategoryPage, $isSearchPage, $categorySlug): string {
        if ($isCategoryPage) {
            return route('categories.show', ['slug' => $categorySlug] + $query);
        }

        return route($isSearchPage ? 'search' : 'products.index', $query);
    };

    $keepWithoutCategory = $isCategoryPage
        ? static fn(array $r = []): array => $keep($r + ['category' => null])
        : $keep;

    $resetUrl = $isSearchPage ? route('search', ['q' => (string) $filters['q']]) : $baseUrl();

    $sorts = [
        '' => 'catalog.sort.default',
        'newest' => 'catalog.sort.newest',
        'price_asc' => 'catalog.sort.price_asc',
        'price_desc' => 'catalog.sort.price_desc',
        'name' => 'catalog.sort.name',
        'best_selling' => 'catalog.sort.best_selling',
    ];

    $range = is_array($facets['price_range'] ?? null) ? $facets['price_range'] : null;

@endphp

@section('content')

    @if ($categorySlug !== null)
        {{-- Breadcrumb --}}
        <nav aria-label="Breadcrumb" class="mb-6 flex items-center gap-2 text-sm text-gray-500">

            <a href="{{ route('home') }}" class="hover:text-amber-600">
                {{ t('nav.home') }}
            </a>

            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
            </svg>

            <a href="{{ route('products.index') }}" class="hover:text-amber-600">
                {{ t('nav.shop') }}
            </a>

            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
            </svg>

            <span class="font-medium text-gray-900">
                {{ $title }}
            </span>
        </nav>

        {{-- Category Heading --}}
        <div class="mb-8">

            <p class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500">
                {{ t('catalog.category_label') }}
            </p>

            <h1 class="text-3xl font-bold text-gray-900 md:text-4xl">
                {{ $title }}
            </h1>

            <p class="mt-3 text-sm text-gray-500">
                {{ t('catalog.product_count', ['n' => num((string) count($products))]) }}
            </p>

        </div>
    @else
        <div class="page-head">
            <h1>{{ $title }}</h1>
        </div>
    @endif

    <div class="layout-catalog">
        <aside class="filters" aria-label="{{ t('catalog.filters') }}">
            <details open>
                <summary>{{ t('catalog.filters') }}</summary>
                <form method="get" action="{{ $baseUrl() }}">
                    @if (($filters['q'] ?? '') !== '')
                        <input type="hidden" name="q" value="{{ $filters['q'] }}">
                    @endif
                    @if ($categorySlug === null && ($filters['category'] ?? '') !== '')
                        <input type="hidden" name="category" value="{{ $filters['category'] }}">
                    @endif

                    <label for="sort">{{ t('catalog.sort') }}</label>
                    <select id="sort" name="sort">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}"
                                {{ ($filters['sort'] ?? '') === (string) $value ? 'selected' : '' }}>{{ t($label) }}
                            </option>
                        @endforeach
                    </select>

                    <fieldset>
                        <legend>{{ t('catalog.price') }}</legend>
                        <div class="two">
                            <input name="price_min" inputmode="decimal" aria-label="{{ t('catalog.price_min') }}"
                                placeholder="{{ $range !== null ? Money::group((string) ($range['minimum']['amount'] ?? '')) : t('catalog.price_min') }}"
                                value="{{ $filters['price_min'] ?? '' }}">
                            <input name="price_max" inputmode="decimal" aria-label="{{ t('catalog.price_max') }}"
                                placeholder="{{ $range !== null ? Money::group((string) ($range['maximum']['amount'] ?? '')) : t('catalog.price_max') }}"
                                value="{{ $filters['price_max'] ?? '' }}">
                        </div>
                    </fieldset>

                    @if (!empty($facets['brands']))
                        <label for="brand">{{ t('catalog.brand') }}</label>
                        <select id="brand" name="brand">
                            <option value="">{{ t('catalog.any') }}</option>
                            @foreach ($facets['brands'] as $brand)
                                <option value="{{ $brand['slug'] ?? '' }}"
                                    {{ ($filters['brand'] ?? '') === ($brand['slug'] ?? null) ? 'selected' : '' }}>
                                    {{ $brand['name'] ?? '' }}{{ isset($brand['count']) ? ' (' . num((string) $brand['count']) . ')' : '' }}
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
                                <option value="{{ $label }}"
                                    {{ ($filters['attributes'][$slug] ?? '') === $label ? 'selected' : '' }}>
                                    {{ $label }}</option>
                            @endforeach
                        </select>
                    @endforeach

                    <label class="check"><input type="checkbox" name="in_stock" value="1"
                            {{ ($filters['in_stock'] ?? '') === 'true' ? 'checked' : '' }}>
                        {{ t('catalog.in_stock_only') }}</label>

                    <div class="actions">
                        <button class="btn !text-white" type="submit">{{ t('catalog.apply') }}</button>
                        <a class="btn btn-quiet !text-white" href="{{ $resetUrl }}">{{ t('catalog.reset') }}</a>
                    </div>
                </form>
            </details>
        </aside>

        <section aria-live="polite">
            @if ($error !== null)
                {{-- Error Message --}}
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-600" role="alert">
                    {{ t($error) }}
                </div>
            @elseif ($products === [])
                {{-- Empty Category / No Products --}}
                <div class="py-16 text-center">

                    <h2 class="text-xl font-semibold text-gray-800">
                        {{ t('catalog.no_results') }}
                    </h2>

                    <p class="mt-2 text-sm text-gray-500">
                        {{ t('catalog.category_empty') }}
                    </p>

                    <a href="{{ route('products.index') }}"
                        class="mt-6 inline-flex items-center justify-center rounded-md
                   bg-sky-600 px-6 py-3 text-sm font-semibold text-white
                   transition hover:bg-sky-700">
                        {{ t('catalog.browse_all') }}
                    </a>

                </div>
            @else
                <div class="grid">
                    @foreach ($products as $product)
                        @include('partials.product_card', ['product' => $product])
                    @endforeach
                </div>

                <nav class="pager" aria-label="{{ t('catalog.pagination') }}">
                    @if ($hasCursor)
                        <a class="btn btn-quiet"
                            href="{{ $baseUrl($keepWithoutCategory()) }}">{{ t('catalog.first_page') }}</a>
                    @endif
                    @if ($hasMore && $nextCursor !== null)
                        <a class="btn" rel="next"
                            href="{{ $baseUrl($keepWithoutCategory(['cursor' => $nextCursor])) }}">{{ t('catalog.next') }}</a>
                    @endif
                </nav>
            @endif
        </section>
    </div>
@endsection
