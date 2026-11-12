{{-- Category navigation: the ERP's categories, none drawn when it has none. --}}
@if (!empty($categories))
    @php
        // The marquee scrolls by half its track, so it only loops cleanly when one group is wider
        // than the screen. A short list stays put and scrolls by hand instead.
        $marquee = count($categories) >= 8;
    @endphp
    <nav class="category-navigation w-full" aria-label="{{ t('nav.categories') }}">

        <div class="category-navigation__inner">

            {{-- All Products --}}
            <a href="{{ route('products.index') }}" class="category-navigation__all">
                {{ t('nav.all') }}
            </a>

            <div class="category-marquee{{ $marquee ? '' : ' category-marquee--static' }}">
                <div class="category-marquee__track{{ $marquee ? '' : ' category-marquee__track--static' }}">

                    @foreach ($marquee ? [false, true] : [false] as $isDuplicate)
                        {{-- The second group repeats the first so the marquee loops without a gap. --}}
                        <div class="category-marquee__group" @if ($isDuplicate) aria-hidden="true" @endif>
                            @foreach ($categories as $category)
                                @continue(($category['slug'] ?? '') === '')
                                <a @if ($isDuplicate) tabindex="-1" @endif
                                    href="{{ route('categories.show', ['slug' => (string) $category['slug']]) }}"
                                    class="category-navigation__link">
                                    {{ $category['name'] ?? '' }}
                                </a>
                            @endforeach
                        </div>
                    @endforeach

                </div>
            </div>
        </div>
    </nav>
@endif
