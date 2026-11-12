@php
    $brand = $site['branding'] ?? [];
    $shopName = (string) ($site['name'] ?? config('app.name'));
    $pageTitle = isset($title) && $title !== '' && $title !== $shopName ? $title . ' · ' . $shopName : $shopName;
    $logo = safe_url($brand['logo_url'] ?? null);
    $primary = (string) ($brand['primary_color'] ?? '#1f2937');
    $secondary = (string) ($brand['secondary_color'] ?? '#c2410c');
    $initial = mb_strtoupper(mb_substr(trim($shopName) === '' ? '?' : trim($shopName), 0, 1));
    $locale = $locale ?? 'en';
    $other = $locale === 'bn' ? 'en' : 'bn';
    $cartCount = $cartCount ?? 0;
@endphp

<!doctype html>
<html lang="{{ $locale }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $pageTitle }}</title>

    @if (!empty($description))
        <meta name="description" content="{{ $description }}">
    @endif

    @if (!empty($noindex))
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta name="theme-color" content="#F8FAFC">

    @if (!empty($image))
        <meta property="og:image" content="{{ safe_url($image) }}">
        <meta property="og:title" content="{{ $title ?? '' }}">
    @endif

    <style>
        :root {
            --brand: {{ $primary }};
            --on-brand: {{ on_colour($primary) }};
            --accent: {{ $secondary }};
            --on-accent: {{ on_colour($secondary) }};
        }
    </style>

    <link rel="stylesheet" href="{{ versioned_asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/tailwind.css') }}">
    <script src="{{ versioned_asset('js/theme.js') }}"></script>
</head>

<body class="bg-[#F8FAFC] text-slate-800">

    <a class="skip" href="#main">{{ t('nav.skip') }}</a>

    @php
        // Shared navigation button styling.
        $navBtn =
            'inline-flex items-center justify-center rounded-xl border border-sky-600 bg-sky-600 text-[15px] font-semibold text-white cursor-pointer transition-all duration-200 hover:border-sky-700 hover:bg-sky-700 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2 dark:border-sky-600 dark:bg-sky-600 dark:text-white dark:hover:border-sky-700 dark:hover:bg-sky-700 dark:hover:text-white';

        $trackBtn =
            'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-transparent text-[15px] font-semibold text-black cursor-pointer transition-all duration-200 hover:-translate-y-0.5 hover:border-sky-600 hover:bg-sky-600 hover:text-white hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2';

        // Dropdown menu items.
        $menuItem =
            'block w-full rounded-lg px-3 py-2.5 text-start text-[15px] font-medium text-slate-700 cursor-pointer transition-colors hover:bg-sky-50 hover:text-sky-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-sky-400';

        // Category pills.
        $chip =
            'shrink-0 whitespace-nowrap rounded-full border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-800 transition-colors hover:border-sky-700 hover:bg-sky-700 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 dark:border-sky-900 dark:bg-sky-950/60 dark:text-sky-200 dark:hover:border-sky-700 dark:hover:bg-sky-700 dark:hover:text-white';
    @endphp

    <header
        class="relative z-50 w-full border-b border-slate-200 bg-[#F8FAFC] text-slate-900 shadow-[0_4px_14px_rgba(15,23,42,0.06)]">

        {{-- Main Header --}}
        <div class="mx-auto w-full max-w-[1280px] px-4 py-3 md:px-6">

            <div
                class="storefront-header-row flex min-h-[56px] flex-wrap items-center gap-x-3 gap-y-3 md:min-h-[64px] md:flex-nowrap md:gap-x-5">

                {{-- Brand Logo --}}
                <a href="{{ route('home') }}" aria-label="{{ $shopName }}"
                    class="storefront-header-brand order-1 flex min-w-0 max-w-[calc(100%-7rem)] shrink-0 items-center rounded-lg no-underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 min-[380px]:max-w-[calc(100%-8rem)] sm:max-w-[220px]">

                    @if ($logo !== '')
                        <img src="{{ $logo }}" alt="{{ $shopName }}" width="240" height="64"
                            class="h-9 w-auto max-w-full object-contain md:h-14" data-fallback="brand-mark">
                    @endif

                    <span class="brand-mark text-2xl font-bold text-sky-600" aria-hidden="true" {!! $logo !== '' ? ' hidden' : '' !!}>
                        {{ $initial }}
                    </span>

                    @if ($logo === '')
                        <span
                            class="brand-name storefront-header-brand-name ml-2 min-w-0 truncate text-base font-extrabold tracking-tight text-sky-600 sm:text-xl md:text-2xl">
                            {{ $shopName }}
                        </span>
                    @endif
                </a>

                {{-- Search Form --}}
                <form action="{{ route('search') }}" method="get" role="search"
                    class="storefront-header-search order-3 flex h-11 w-full min-w-0 overflow-hidden rounded-xl border border-slate-300 bg-[#F8FAFC] shadow-sm transition-all duration-200 focus-within:border-sky-500 focus-within:ring-2 focus-within:ring-sky-100 md:order-2 md:h-12 md:w-auto md:max-w-3xl md:flex-1 dark:border-slate-300 dark:bg-[#F8FAFC]">

                    <label class="sr-only" for="q">
                        {{ t('nav.search') }}
                    </label>

                    {{-- Search Input --}}
                    <div class="flex min-w-0 flex-1 items-center px-4 md:px-5">

                        <input id="q" name="q" type="search" maxlength="100"
                            value="{{ $searchTerm ?? '' }}" placeholder="{{ t('nav.search_placeholder') }}"
                            class="h-full w-full min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-base font-normal text-slate-700 outline-none ring-0 placeholder:text-slate-400 focus:border-0 focus:outline-none focus:ring-0">

                    </div>

                    {{-- Search Button --}}
                    <button type="submit" aria-label="{{ t('nav.search') }}"
                        class="group m-1 flex w-11 shrink-0 items-center justify-center rounded-lg border-0 bg-sky-600 text-white outline-none ring-0 transition-all duration-200 hover:bg-sky-700 focus:outline-none focus:ring-0 active:scale-95 md:w-12">

                        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                            stroke-linejoin="round" class="transition-transform duration-200 group-hover:scale-110">

                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />

                        </svg>

                    </button>

                </form>

                {{-- Header Actions --}}
                <nav class="storefront-header-actions order-2 ml-auto flex items-center gap-1 sm:gap-1.5 md:order-3 [&_a]:no-underline [&_button]:no-underline"
                    aria-label="{{ t('nav.account') }}">

                    {{-- Track Order --}}
                    <a href="{{ route('track.form') }}"
                        class="{{ $trackBtn }} storefront-track-btn hidden h-10 px-3.5 lg:inline-flex no-underline hover:no-underline">
                        {{ t('nav.track') }}
                    </a>

                    {{-- Language Switch --}}
                    <form action="{{ route('locale.update') }}" method="post" class="hidden lg:block">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $other }}">

                        <button type="submit" lang="{{ $other }}"
                            class="storefront-locale-btn inline-flex h-10 items-center justify-center rounded-xl border border-slate-400 bg-transparent px-3.5 text-[15px] font-normal text-black cursor-pointer transition-colors hover:border-sky-600 hover:bg-sky-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 no-underline hover:no-underline">
                            {{ $other === 'en' ? 'English' : 'বাংলা' }}
                        </button>
                    </form>

                    {{-- Cart --}}
                    <a href="{{ route('cart.show') }}"
                        class="group relative inline-flex h-11 w-11 items-center justify-center gap-2 rounded-xl border border-sky-600 bg-sky-600 px-3.5 text-[15px] font-semibold text-white no-underline transition-all duration-200 hover:border-sky-700 hover:bg-sky-700 hover:text-white hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 focus-visible:ring-offset-2 sm:w-auto md:h-10">

                        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"
                            class="shrink-0 text-white">
                            <path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.76L20 8H6.2" fill="none"
                                stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" />
                            <circle cx="9.5" cy="20" r="1.4" fill="currentColor" />
                            <circle cx="17" cy="20" r="1.4" fill="currentColor" />
                        </svg>

                        <span class="sr-only sm:not-sr-only">{{ t('nav.cart') }}</span>

                        @if ($cartCount > 0)
                            <span
                                class="absolute -right-1.5 -top-1.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-slate-900 px-1.5 text-xs font-bold leading-none text-white ring-2 ring-white sm:static sm:ring-0"
                                aria-label="{{ t('nav.cart_items', ['n' => (string) $cartCount]) }}">
                                {{ num($cartCount) }}
                            </span>
                        @endif
                    </a>

                    {{-- Mobile / Tablet Menu --}}
                    <details class="storefront-mobile-menu group relative lg:hidden">

                        <summary aria-label="{{ t('nav.menu_open') }}"
                            class="{{ $navBtn }} storefront-mobile-menu-trigger inline-flex h-11 w-11 list-none select-none touch-manipulation rounded-xl md:h-10 md:w-10 [&::-webkit-details-marker]:hidden">

                            <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"
                                focusable="false">
                                <path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" />
                            </svg>

                            <span class="sr-only">{{ t('nav.menu') }}</span>
                        </summary>

                        {{-- Dropdown --}}
                        <div class="storefront-mobile-menu-panel">

                            <a href="{{ route('track.form') }}"
                                class="{{ $menuItem }} no-underline hover:no-underline">
                                {{ t('nav.track') }}
                            </a>

                            <a href="{{ route('about') }}"
                                class="{{ $menuItem }} no-underline hover:no-underline">
                                {{ t('page.about') }}
                            </a>

                            <a href="{{ route('contact') }}"
                                class="{{ $menuItem }} no-underline hover:no-underline">
                                {{ t('page.contact') }}
                            </a>

                            <form action="{{ route('locale.update') }}" method="post"
                                class="storefront-mobile-menu-locale">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $other }}">

                                <button type="submit" lang="{{ $other }}"
                                    class="storefront-mobile-menu-locale-button {{ $menuItem }} w-full text-left no-underline hover:no-underline">
                                    {{ $other === 'en' ? 'English' : 'বাংলা' }}
                                </button>
                            </form>
                        </div>
                    </details>
                </nav>
            </div>
        </div>
    </header>


    @include('partials.category_navigation')



    @if (!($site['accepting_orders'] ?? true))
        <div class="notice notice-warning" role="status">
            <div class="container">
                {{ t('site.not_accepting') }}
            </div>
        </div>
    @endif


    <main id="main" class="w-full  ">

        {{-- Existing Flash Messages --}}
        <div class="container">
            @foreach ($flash ?? [] as $message)
                <div class="flash flash-{{ $message['type'] }}"
                    role="{{ $message['type'] === 'error' ? 'alert' : 'status' }}">{{ $message['message'] }}</div>
            @endforeach
        </div>

        {{-- Homepage Hero Banner --}}
        @if (request()->routeIs('home'))
            @php
                // The banner is the shop's own, from the ERP; nothing is drawn over the picture.
                $banner = safe_url($brand['banner_url'] ?? null);
                $bannerLink = is_array($brand['banner_link'] ?? null) ? $brand['banner_link'] : null;
                $bannerLabel = t('home.banner_label', ['name' => $shopName]);
                $bannerClasses = 'block h-auto w-full object-cover object-center';
            @endphp
            <section class="relative mt-5 w-full pb-4 sm:pb-6" aria-label="{{ $bannerLabel }}">

                <div class="mx-auto w-full max-w-[1280px] px-4 md:px-6">
                    <div class="banner {{ $banner === '' ? 'banner-plain ' : '' }}mb-0 w-full sm:rounded-2xl">

                        @if ($banner !== '')
                            @if ($bannerLink !== null)
                                <a class="banner-link" href="{{ $bannerLink['url'] }}"{!! $bannerLink['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' !!}
                                    aria-label="{{ $bannerLabel }}">
                                    <img src="{{ $banner }}" alt="" width="1600" height="560"
                                        class="{{ $bannerClasses }}" fetchpriority="high" decoding="async"
                                        data-fallback="banner-fallback">
                                </a>
                            @else
                                <img src="{{ $banner }}" alt="" width="1600" height="560"
                                    class="{{ $bannerClasses }}" fetchpriority="high" decoding="async"
                                    data-fallback="banner-fallback">
                            @endif
                            <div class="banner-fallback" hidden>
                                <h1>{{ $shopName }}</h1>
                                @if (!empty($brand['tagline']))
                                    <p>{{ $brand['tagline'] }}</p>
                                @endif
                            </div>
                        @else
                            <div class="banner-fallback">
                                <h1>{{ $shopName }}</h1>
                                @if (!empty($brand['tagline']))
                                    <p>{{ $brand['tagline'] }}</p>
                                @endif
                            </div>
                        @endif

                    </div>
                </div>

            </section>
        @endif
        {{-- Homepage Sections Only --}}
        @if (request()->routeIs('home'))
            @include('partials.categorybyproduct')

            @include('partials.trending')
        @endif

        {{-- Existing Page Content --}}
        <div
            class="{{ request()->routeIs('products.*', 'categories.show', 'search') ? 'catalog-page-container' : 'container' }}">
            @yield('content')

        </div>

    </main>



    {{--
    @include('partials.categorybyproduct')
    @include('partials.trending') --}}


    <footer class="w-full border-t border-gray-200 bg-[#F8FAFC] text-slate-700">

        {{-- Main Footer --}}
        <div class="mx-auto max-w-[1280px] px-4 py-12 sm:px-6 lg:py-16">

            <div class="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-4 lg:gap-12">

                {{-- Brand --}}
                <div class="sm:col-span-2">

                    <a href="{{ route('home') }}" class="mb-4 inline-flex items-center no-underline"
                        aria-label="{{ $shopName }}">

                        @if ($logo !== '')
                            <img src="{{ $logo }}" alt=""
                                class="h-14 w-auto max-w-[180px] object-contain" loading="lazy"
                                data-fallback="footer-brand-name">
                        @endif

                        <span class="footer-brand-name text-lg font-extrabold text-sky-600"
                            {!! $logo !== '' ? ' hidden' : '' !!}>{{ $shopName }}</span>

                    </a>

                    @if (!empty($brand['tagline']))
                        <p class="max-w-xs text-sm leading-7 text-slate-500">
                            {{ $brand['tagline'] }}
                        </p>
                    @endif

                </div>


                {{-- Shop --}}
                <div>

                    <h3 class="mb-5 text-sm font-bold uppercase tracking-wider text-slate-900">
                        {{ t('footer.shop') }}
                    </h3>

                    <ul class="m-0 list-none space-y-3 p-0 text-sm">

                        <li>
                            <a href="{{ route('products.index') }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('nav.all') }}
                            </a>
                        </li>

                        <li>
                            <a href="{{ route('products.index', ['sort' => 'newest']) }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('home.new_arrivals') }}
                            </a>
                        </li>

                        <li>
                            <a href="{{ route('products.index', ['sort' => 'best_selling']) }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('home.best_sellers') }}
                            </a>
                        </li>

                        <li>
                            <a href="{{ route('cart.show') }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('nav.cart') }}
                            </a>
                        </li>

                    </ul>

                </div>


                {{-- Help --}}
                <div>

                    <h3 class="mb-5 text-sm font-bold uppercase tracking-wider text-slate-900">
                        {{ t('footer.help') }}
                    </h3>

                    <ul class="m-0 list-none space-y-3 p-0 text-sm text-slate-500">

                        <li>
                            <a href="{{ route('track.form') }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('nav.track') }}
                            </a>
                        </li>

                        <li>
                            <a href="{{ route('about') }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('page.about') }}
                            </a>
                        </li>

                        <li>
                            <a href="{{ route('contact') }}"
                                class="text-slate-500 no-underline transition hover:text-sky-600">
                                {{ t('page.contact') }}
                            </a>
                        </li>

                        @if (!empty($site['contact']['phone']))
                            <li>{{ $site['contact']['phone'] }}</li>
                        @endif

                        @if (!empty($site['contact']['email']))
                            <li>{{ $site['contact']['email'] }}</li>
                        @endif

                        @if (!empty($site['contact']['address']))
                            <li>{{ $site['contact']['address'] }}</li>
                        @endif

                    </ul>

                </div>

            </div>

        </div>


        {{-- Bottom Footer --}}
        <div class="border-t border-gray-200 bg-[#F8FAFC]">

            <div
                class="mx-auto flex max-w-[1280px] flex-col items-center justify-between gap-5 px-4 py-6 text-center sm:px-6 md:flex-row md:text-left">

                <p class="text-xs text-slate-500">
                    {{ t('footer.rights', ['year' => gmdate('Y'), 'name' => $shopName]) }}
                </p>

                <div class="flex flex-col items-center gap-4 sm:flex-row">

                    {{-- Payment methods: what the ERP has switched on, not a picture. --}}
                    @php
                        $acceptedGateways = array_values(array_filter((array) ($site['gateways'] ?? []), 'is_array'));
                        $acceptsCod = !empty($site['cod']['enabled']);
                    @endphp
                    @if ($acceptedGateways !== [] || $acceptsCod)
                        <div class="flex flex-wrap items-center justify-center gap-3 text-xs text-slate-500">
                            <span class="font-semibold">{{ t('footer.we_accept') }}</span>

                            @foreach ($acceptedGateways as $gateway)
                                <span>@include('partials.gateway', ['gateway' => $gateway])</span>
                            @endforeach

                            @if ($acceptsCod)
                                <strong>{{ t('payment.cod') }}</strong>
                            @endif
                        </div>

                        <span class="hidden h-5 w-px bg-gray-200 sm:block"></span>
                    @endif

                    <span class="text-xs text-slate-500">
                        {{ t('footer.powered') }}
                    </span>

                    <a href="#main"
                        class="text-xs font-semibold text-sky-600 no-underline transition hover:text-sky-700">
                        {{ t('footer.top') }} ↑
                    </a>

                </div>

            </div>

        </div>

    </footer>


    <script src="{{ versioned_asset('js/app.js') }}" defer></script>
</body>

</html>
