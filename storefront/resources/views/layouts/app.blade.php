@php
    $brand = $site['branding'] ?? [];
    $shopName = (string) ($site['name'] ?? config('app.name'));
    $pageTitle = isset($title) && $title !== '' && $title !== $shopName ? $title.' · '.$shopName : $shopName;
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
    @if (! empty($description))<meta name="description" content="{{ $description }}">@endif
    @if (! empty($noindex))<meta name="robots" content="noindex, nofollow">@endif
    <meta name="theme-color" content="{{ $primary }}">
    @if (! empty($image))
        <meta property="og:image" content="{{ safe_url($image) }}">
        <meta property="og:title" content="{{ $title ?? '' }}">
    @endif
    <style>:root{--brand:{{ $primary }};--on-brand:{{ on_colour($primary) }};--accent:{{ $secondary }};--on-accent:{{ on_colour($secondary) }}}</style>
    <link rel="stylesheet" href="{{ versioned_asset('css/app.css') }}">
    <script src="{{ versioned_asset('js/theme.js') }}"></script>
</head>
<body>
<a class="skip" href="#main">{{ t('nav.skip') }}</a>

<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="{{ route('home') }}" aria-label="{{ $shopName }}">
            @if ($logo !== '')
                <img class="brand-logo" src="{{ $logo }}" alt="" width="160" height="40" data-fallback="brand-mark">
            @endif
            <span class="brand-mark" aria-hidden="true"{!! $logo !== '' ? ' hidden' : '' !!}>{{ $initial }}</span>
            @if ($logo === '')
                <span class="brand-name">{{ $shopName }}</span>
            @endif
        </a>

        <form class="search" action="{{ route('search') }}" method="get" role="search">
            <label class="sr-only" for="q">{{ t('nav.search') }}</label>
            <input id="q" name="q" type="search" maxlength="100" value="{{ $searchTerm ?? '' }}" placeholder="{{ t('nav.search_placeholder') }}">
            <button type="submit">{{ t('nav.search') }}</button>
        </form>

        <nav class="header-actions" aria-label="{{ t('nav.account') }}">
            <a class="hide-small" href="{{ route('track.form') }}">{{ t('nav.track') }}</a>
            <form action="{{ route('locale.update') }}" method="post" class="locale hide-small">
                @csrf
                <input type="hidden" name="locale" value="{{ $other }}">
                <button type="submit" class="link" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'বাংলা' }}</button>
            </form>
            <button type="button" class="theme-toggle" data-theme-toggle data-label-dark="{{ t('nav.theme_to_dark') }}" data-label-light="{{ t('nav.theme_to_light') }}" aria-label="{{ t('nav.theme_to_dark') }}" hidden>
                <svg class="icon-moon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <svg class="icon-sun" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
            <a class="cart-link" href="{{ route('cart.show') }}">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.76L20 8H6.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.4" fill="currentColor"/><circle cx="17" cy="20" r="1.4" fill="currentColor"/></svg>
                <span class="cart-text">{{ t('nav.cart') }}</span>
                @if ($cartCount > 0)<span class="badge" aria-label="{{ t('nav.cart_items', ['n' => (string) $cartCount]) }}">{{ num($cartCount) }}</span>@endif
            </a>
            <details class="menu">
                <summary aria-label="{{ t('nav.menu_open') }}">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="sr-only">{{ t('nav.menu') }}</span>
                </summary>
                <div class="menu-panel">
                    <a href="{{ route('track.form') }}">{{ t('nav.track') }}</a>
                    <a href="{{ route('about') }}">{{ t('page.about') }}</a>
                    <a href="{{ route('contact') }}">{{ t('page.contact') }}</a>
                    <form action="{{ route('locale.update') }}" method="post">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $other }}">
                        <button type="submit" class="link" lang="{{ $other }}">{{ $other === 'en' ? 'English' : 'বাংলা' }}</button>
                    </form>
                </div>
            </details>
        </nav>
    </div>

    @if (! empty($categories))
        <nav class="categories" aria-label="{{ t('nav.categories') }}">
            <div class="container">
                <a href="{{ route('products.index') }}">{{ t('nav.all') }}</a>
                @foreach ($categories as $category)
                    <a href="{{ route('categories.show', ['slug' => (string) ($category['slug'] ?? '')]) }}">{{ $category['name'] ?? '' }}</a>
                @endforeach
            </div>
        </nav>
    @endif
</header>

@if (! ($site['accepting_orders'] ?? true))
    <div class="notice notice-warning" role="status"><div class="container">{{ t('site.not_accepting') }}</div></div>
@endif

<main id="main" class="container">
    @foreach ($flash ?? [] as $message)
        <div class="flash flash-{{ $message['type'] }}" role="{{ $message['type'] === 'error' ? 'alert' : 'status' }}">{{ $message['message'] }}</div>
    @endforeach

    @yield('content')
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <strong>{{ $shopName }}</strong>
            @if (! empty($brand['tagline']))<p class="muted">{{ $brand['tagline'] }}</p>@endif
        </div>
        <div>
            <strong>{{ t('footer.shop') }}</strong>
            <ul>
                <li><a href="{{ route('products.index') }}">{{ t('nav.all') }}</a></li>
                <li><a href="{{ route('cart.show') }}">{{ t('nav.cart') }}</a></li>
                <li><a href="{{ route('track.form') }}">{{ t('nav.track') }}</a></li>
            </ul>
        </div>
        <div>
            <strong>{{ t('footer.help') }}</strong>
            <ul>
                <li><a href="{{ route('about') }}">{{ t('page.about') }}</a></li>
                <li><a href="{{ route('contact') }}">{{ t('page.contact') }}</a></li>
                @if (! empty($site['contact']['phone']))<li>{{ $site['contact']['phone'] }}</li>@endif
                @if (! empty($site['contact']['email']))<li>{{ $site['contact']['email'] }}</li>@endif
                @if (! empty($site['contact']['address']))<li>{{ $site['contact']['address'] }}</li>@endif
            </ul>
        </div>
    </div>
    <div class="container fine footer-bottom">
        <span>{{ t('footer.rights', ['year' => gmdate('Y'), 'name' => $shopName]) }}</span>
        <span>{{ t('footer.powered') }}</span>
        <a class="to-top" href="#main">{{ t('footer.top') }} ↑</a>
    </div>
</footer>

<script src="{{ versioned_asset('js/app.js') }}" defer></script>
</body>
</html>
