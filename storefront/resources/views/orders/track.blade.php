@extends('layouts.app')

@section('content')
<div class="track-page">
    <div class="track-card">

        <div class="track-card-head">
            <span class="track-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="26" height="26" focusable="false">
                    <path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z" fill="none" stroke="currentColor"
                        stroke-width="1.7" stroke-linejoin="round" />
                    <path d="M3 7.5 12 12l9-4.5M12 12v9" fill="none" stroke="currentColor"
                        stroke-width="1.7" stroke-linejoin="round" />
                </svg>
            </span>
            <h1>{{ t('track.title') }}</h1>
            <p>{{ t('track.help') }}</p>
        </div>

        <form class="track-form" action="{{ route('track.submit') }}" method="post">
            @csrf

            <div class="track-field">
                <label for="reference">{{ t('track.reference') }}</label>
                <input id="reference" name="reference" required maxlength="64" autocomplete="off"
                    placeholder="ORD-… / SF-…">
            </div>

            <div class="track-field">
                <label for="mobile">{{ t('track.mobile') }}</label>
                <input id="mobile" name="mobile" type="tel" inputmode="tel" required maxlength="32"
                    autocomplete="tel" placeholder="01XXXXXXXXX">
            </div>

            <button class="btn btn-accent track-submit" type="submit">{{ t('track.find') }}</button>
        </form>

    </div>
</div>
@endsection
