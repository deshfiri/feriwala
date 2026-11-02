@extends('layouts.app')

@section('content')
<div class="page-head"><h1>{{ t('page.about') }}</h1></div>
<article class="prose">
    @php $about = paragraphs((string) ($site['branding']['about'] ?? '')); @endphp
    @if ($about === [])
        <p>{{ t('page.about_empty', ['name' => (string) $site['name']]) }}</p>
    @else
        @foreach ($about as $paragraph)<p>{{ $paragraph }}</p>@endforeach
    @endif
</article>
@endsection
