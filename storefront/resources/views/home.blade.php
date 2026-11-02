@extends('layouts.app')

@section('content')
@php
    $banner = safe_url($site['branding']['banner_url'] ?? null);
    $link = is_array($site['branding']['banner_link'] ?? null) ? $site['branding']['banner_link'] : null;
    $label = t('home.banner_label', ['name' => (string) $site['name']]);
@endphp
@if ($banner !== '')
    {{-- The picture is the whole control: nothing is drawn over it. --}}
    <section class="banner" aria-label="{{ $label }}">
        @if ($link !== null)
            <a class="banner-link" href="{{ $link['url'] }}"{!! $link['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' !!} aria-label="{{ $label }}">
                <img class="banner-image" src="{{ $banner }}" alt="" width="1600" height="560" fetchpriority="high" data-fallback="banner-fallback">
            </a>
        @else
            <img class="banner-image" src="{{ $banner }}" alt="" width="1600" height="560" fetchpriority="high" data-fallback="banner-fallback">
        @endif
        <div class="banner-fallback" hidden>
            <h1>{{ $site['name'] }}</h1>
            @if (! empty($site['branding']['tagline']))<p>{{ $site['branding']['tagline'] }}</p>@endif
        </div>
    </section>
@else
    <section class="banner banner-plain" aria-label="{{ $label }}">
        <div class="banner-fallback">
            <h1>{{ $site['name'] }}</h1>
            @if (! empty($site['branding']['tagline']))<p>{{ $site['branding']['tagline'] }}</p>@endif
        </div>
    </section>
@endif

@if ($deals !== [])
    <section aria-labelledby="deals">
        <div class="section-head">
            <h2 id="deals">{{ t('home.deals') }}</h2>
        </div>
        <div class="grid">
            @foreach ($deals as $product)@include('partials.product_card', ['product' => $product])@endforeach
        </div>
    </section>
@endif

<section aria-labelledby="featured">
    <div class="section-head">
        <h2 id="featured">{{ t($featuredIsNewest ? 'home.new_arrivals' : 'home.featured') }}</h2>
        <a href="{{ route('products.index') }}">{{ t('home.view_all') }}</a>
    </div>
    @if ($featured === [])
        <div class="empty">
            <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false"><path d="M4 8l8-4 8 4v8l-8 4-8-4V8zm0 0l8 4m8-4l-8 4m0 8v-8" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            <p>{{ t('catalog.empty') }}</p>
        </div>
    @else
        <div class="grid">
            @foreach ($featured as $product)@include('partials.product_card', ['product' => $product])@endforeach
        </div>
    @endif
</section>

@if ($newest !== [])
    <section aria-labelledby="newest">
        <div class="section-head">
            <h2 id="newest">{{ t('home.new_arrivals') }}</h2>
            <a href="{{ route('products.index', ['sort' => 'newest']) }}">{{ t('home.view_all') }}</a>
        </div>
        <div class="grid">
            @foreach ($newest as $product)@include('partials.product_card', ['product' => $product])@endforeach
        </div>
    </section>
@endif
@endsection
