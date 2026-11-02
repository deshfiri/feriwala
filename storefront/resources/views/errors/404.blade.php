@extends('layouts.app')

@section('content')
<div class="empty center">
    <h1>404</h1>
    <p>{{ t('error.not_found') }}</p>
    <a class="btn" href="{{ route('home') }}">{{ t('nav.home') }}</a>
</div>
@endsection
