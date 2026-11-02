@extends('layouts.app')

@section('content')
@php $contact = $site['contact'] ?? []; @endphp
<div class="page-head"><h1>{{ t('page.contact') }}</h1></div>
<article class="prose">
    @if (empty($contact['phone']) && empty($contact['email']) && empty($contact['address']))
        <p>{{ t('page.contact_empty') }}</p>
    @else
        <dl class="contact">
            @if (! empty($contact['phone']))<dt>{{ t('contact.phone') }}</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $contact['phone']) }}">{{ $contact['phone'] }}</a></dd>@endif
            @if (! empty($contact['email']))<dt>{{ t('contact.email') }}</dt><dd><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></dd>@endif
            @if (! empty($contact['address']))<dt>{{ t('contact.address') }}</dt><dd>{{ $contact['address'] }}</dd>@endif
        </dl>
    @endif
</article>
@endsection
