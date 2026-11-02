@extends('layouts.app')

@section('content')
<div class="empty center">
    <h1>{{ t('error.unavailable_title') }}</h1>
    <p>{{ t('error.unavailable_body') }}</p>
    <a class="btn" href="javascript:location.reload()">{{ t('error.retry') }}</a>
</div>
@endsection
