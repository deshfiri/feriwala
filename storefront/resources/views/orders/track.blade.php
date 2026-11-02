@extends('layouts.app')

@section('content')
<div class="page-head"><h1>{{ t('track.title') }}</h1></div>

<form class="checkout narrow" action="{{ route('track.submit') }}" method="post">
    @csrf
    <p class="muted">{{ t('track.help') }}</p>

    <label for="reference">{{ t('track.reference') }}</label>
    <input id="reference" name="reference" required maxlength="64" autocomplete="off" placeholder="ORD-… / SF-…">

    <label for="mobile">{{ t('track.mobile') }}</label>
    <input id="mobile" name="mobile" type="tel" inputmode="tel" required maxlength="32" autocomplete="tel">

    <button class="btn btn-accent" type="submit">{{ t('track.find') }}</button>
</form>
@endsection
