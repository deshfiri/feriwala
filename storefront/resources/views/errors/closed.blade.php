@extends('layouts.app')

@section('content')
@php
    $key = match ($closedStatus) {
        'maintenance' => 'closed.maintenance',
        'preparing' => 'closed.preparing',
        'unreachable' => 'closed.unreachable',
        default => 'closed.unavailable',
    };
@endphp
<div class="empty center">
    <h1>{{ t($key.'_title') }}</h1>
    <p>{{ $message !== null && $message !== '' ? $message : t($key.'_body') }}</p>
    @if ($closedStatus === 'unreachable')<a class="btn" href="javascript:location.reload()">{{ t('error.retry') }}</a>@endif
</div>
@endsection
