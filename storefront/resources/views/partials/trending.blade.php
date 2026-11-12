@php
    use App\Services\Catalog;

    // Set by CatalogController::home(): the ERP's featured products, or its newest when none are featured.
    $trendingProducts = $featured ?? [];
    $trendingIsNewest = $featuredIsNewest ?? false;
@endphp

<section class="w-full bg-[#F8FAFC] py-6 md:py-8 dark:bg-[#F8FAFC]" aria-labelledby="trending-title">
    <div class="mx-auto w-full max-w-[1280px] px-4 md:px-6">

        {{-- Heading + See All --}}
        <div class="mb-4 flex items-center justify-between gap-4 md:mb-6">
            <h2 id="trending-title"
                class="flex items-center gap-3 text-xl font-bold tracking-tight text-slate-900 md:text-2xl dark:text-slate-100">
                <span class="h-7 w-1 rounded-full bg-sky-500" aria-hidden="true"></span>
                {{ t($trendingIsNewest ? 'home.new_arrivals' : 'home.featured') }}
            </h2>

            <a href="{{ route('products.index') }}"
                class="inline-flex no-underline h-10 items-center gap-1 rounded-lg px-2 text-[15px] font-medium text-sky-700 transition-colors hover:text-sky-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 dark:text-sky-400 dark:hover:text-sky-300">
                {{ t('home.view_all') }}

                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="m9 6 6 6-6 6" />
                </svg>
            </a>
        </div>

        {{-- Products --}}
        @if ($trendingProducts === [])
            <div class="empty">
                <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false"><path d="M4 8l8-4 8 4v8l-8 4-8-4V8zm0 0l8 4m8-4l-8 4m0 8v-8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                <p>{{ t('catalog.empty') }}</p>
            </div>
        @else
            <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                @foreach ($trendingProducts as $product)
                    @php
                        $name = (string) ($product['name'] ?? '');
                        $slug = (string) ($product['slug'] ?? '');
                        $image = safe_url(Catalog::primaryImage($product));
                        $price = Catalog::startingPrice($product);
                        $inStock = Catalog::inStock($product);
                        $variants = array_values(array_filter((array) ($product['variants'] ?? []), 'is_array'));
                        $quickAdd = $inStock && count($variants) === 1 && ($variants[0]['sku'] ?? '') !== '';
                        $productUrl = route('products.show', ['slug' => $slug]);
                        $buttonBase = 'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white transition-colors focus-visible:outline-none md:h-10 md:w-10';
                    @endphp

                    <li>
                        <article
                            class="group flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-2.5 transition-shadow hover:shadow-lg hover:shadow-slate-200/70 sm:p-3 dark:border-slate-700 dark:bg-slate-900 dark:hover:shadow-none">

                            {{-- Image --}}
                            <a href="{{ $productUrl }}"
                                class="relative block aspect-square overflow-hidden rounded-xl bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 dark:bg-slate-800">
                                @if ($image !== '')
                                    <img src="{{ $image }}" alt="{{ $name }}" width="400" height="400"
                                        loading="lazy" decoding="async" data-fallback="trending-fallback"
                                        class="h-full w-full object-contain p-2 transition duration-300 {{ $inStock ? 'motion-safe:group-hover:scale-105' : 'opacity-60 blur-[3px]' }}">
                                @endif
                                <span
                                    class="trending-fallback absolute inset-0 flex items-center justify-center text-5xl font-bold text-slate-400"
                                    aria-hidden="true" {!! $image !== '' ? ' hidden' : '' !!}>{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>

                                @unless ($inStock)
                                    <span
                                        class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full bg-slate-800 px-4 py-2 text-[11px] font-bold uppercase tracking-wider text-white sm:text-xs">
                                        {{ t('product.out_of_stock') }}
                                    </span>
                                @endunless
                            </a>

                            {{-- Info --}}
                            <div class="mt-3 flex flex-1 flex-col">
                                <h3
                                    class="line-clamp-2 min-h-[2.5rem] text-[15px] font-semibold leading-snug text-slate-900 dark:text-slate-100">
                                    <a href="{{ $productUrl }}"
                                        class="no-underline transition-colors hover:text-sky-700 focus-visible:outline-none focus-visible:underline dark:hover:text-sky-300">
                                        {{ $name }}
                                    </a>
                                </h3>

                                {{-- Price + add button --}}
                                <div class="mt-auto flex items-center justify-between gap-2 pt-4">
                                    @if ($price !== null)
                                        <span class="text-lg font-bold text-slate-900 dark:text-slate-100">
                                            @if (count($variants) > 1)
                                                <span class="block text-xs font-medium text-slate-500">{{ t('product.from') }}</span>
                                            @endif
                                            {{ money($price) }}
                                        </span>
                                    @else
                                        <span></span>
                                    @endif

                                    @if ($quickAdd)
                                        <form action="{{ route('cart.add') }}" method="post" data-once>
                                            @csrf
                                            <input type="hidden" name="product" value="{{ $slug }}">
                                            <input type="hidden" name="sku" value="{{ $variants[0]['sku'] }}">
                                            <input type="hidden" name="quantity"
                                                value="{{ (string) max(1, (int) ($product['min_order_quantity'] ?? 1)) }}">
                                            <button type="submit" aria-label="{{ t('product.quick_add') }}: {{ $name }}"
                                                class="{{ $buttonBase }} cursor-pointer bg-sky-600 hover:bg-sky-700 focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900">
                                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    aria-hidden="true" focusable="false">
                                                    <path d="M12 5v14M5 12h14" />
                                                </svg>
                                            </button>
                                        </form>
                                    @elseif ($inStock)
                                        <a href="{{ $productUrl }}"
                                            aria-label="{{ t('product.view_options') }}: {{ $name }}"
                                            class="{{ $buttonBase }} bg-sky-600 no-underline hover:bg-sky-700 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900">
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                aria-hidden="true" focusable="false">
                                                <path d="M12 5v14M5 12h14" />
                                            </svg>
                                        </a>
                                    @else
                                        <button type="button" disabled aria-disabled="true"
                                            aria-label="{{ t('product.out_of_stock') }}: {{ $name }}"
                                            class="{{ $buttonBase }} cursor-not-allowed bg-slate-300 dark:bg-slate-700 dark:text-slate-400">
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                aria-hidden="true" focusable="false">
                                                <path d="M12 5v14M5 12h14" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- Only what the ERP says is true of this shop: cash on delivery, when it is switched on. --}}
        @if (!empty($site['cod']['enabled']))
            <ul class="m-0 mt-8 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 sm:gap-4 lg:mt-10 lg:grid-cols-4">
                <li
                    class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-4 sm:px-5 dark:border-slate-700 dark:bg-slate-900">
                    <span
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-300">
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor"
                            stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                            focusable="false">
                            <rect x="2" y="6" width="20" height="12" rx="2" />
                            <circle cx="12" cy="12" r="3" />
                            <path d="M6 12h.01M18 12h.01" />
                        </svg>
                    </span>

                    <div class="min-w-0">
                        <p class="text-[15px] font-semibold text-slate-900 dark:text-slate-100">{{ t('payment.cod') }}</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ t('payment.cod_help') }}</p>
                    </div>
                </li>
            </ul>
        @endif
    </div>
</section>
