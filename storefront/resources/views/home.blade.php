@extends('layouts.app')

{{-- The banner, the category tiles and the featured products are drawn by the layout on the home page. --}}
@section('content')
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
