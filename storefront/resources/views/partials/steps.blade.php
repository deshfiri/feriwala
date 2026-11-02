@php
    /** $current: 1 = cart, 2 = your details, 3 = review */
    $steps = [1 => 'steps.cart', 2 => 'steps.details', 3 => 'steps.review'];
@endphp
<ol class="steps" aria-label="{{ t('steps.label') }}">
    @foreach ($steps as $number => $label)
        <li{!! $number === $current ? ' aria-current="step"' : '' !!} class="{{ $number < $current ? 'done' : ($number === $current ? 'now' : '') }}">
            <span class="step-dot" aria-hidden="true">{{ num((string) $number) }}</span>
            <span>{{ t($label) }}</span>
        </li>
    @endforeach
</ol>
