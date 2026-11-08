<!DOCTYPE html>
{{--
    The accent an administrator chose under Admin → Branding, painted before
    the stylesheet loads so the page never flashes the default first. Only
    ever `#rrggbb` values: `App\Domain\Settings\Branding` drops anything else,
    and they are escaped here regardless. `BrandingHead` keeps the same three
    properties in step after a save without a full reload.
--}}
@php($accent = $page['props']['branding']['accent'] ?? null)
@php($button = $page['props']['branding']['button'] ?? null)
@php($nav = $page['props']['branding']['nav'] ?? null)
@php($canvas =$page['props']['branding']['background'] ?? null)
@php($brandingStyle = (is_array($accent) ? "--brand-base: {$accent['color']}; --brand-on: {$accent['on']}; --brand-lifted-on: {$accent['lifted_on']};" : '').(is_array($button) ? " --button-base: {$button['color']}; --button-on: {$button['on']}; --button-lifted-on: {$button['lifted_on']};" : '').(is_array($nav) ? " --nav-base: {$nav['color']}; --nav-on: {$nav['on']}; --nav-lifted-on: {$nav['lifted_on']};" : '').(is_string($canvas) ? " --canvas-custom: {$canvas};" : ''))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @if ($brandingStyle !== '') style="{{ $brandingStyle }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{--
            Painted before the stylesheet loads, so the page never flashes a
            colour it is about to stop being.

            These two values must mirror `--background` in resources/css/app.css.
            They did not: this painted pure white while the light canvas is a warm
            off-white, and a near-black while the dark canvas is warmer still — so
            every load began with a flash of the wrong ground.
        --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html:not(.dark) {
                background-color: var(--canvas-custom, oklch(1 0 0));
            }

            html.dark {
                background-color: oklch(0.16 0.003 60);
            }
        </style>

        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        {{--
            Li Ador Noirrit, the Bangla face (Lipighor Font Foundry, EULA at
            lipighor.com/EULA.html). Written here rather than in app.css so the
            URLs come from `asset()`: a font url() inside the stylesheet resolves
            against the Vite dev server, which does not serve `public/`. The
            files live in `public/fonts`, and a face is only declared when its
            file exists — a deployment missing one falls back to Noto Sans
            Bengali with no failed requests. `unicode-range` keeps it to Bengali (plus the
            danda and the zero-width joiners conjuncts need), so English and
            digits on a Bangla page stay Poppins. A Medium request lands on Regular.
        --}}
        @php($banglaFaces = collect([400 => 'Regular', 600 => 'SemiBold', 700 => 'Bold'])->filter(fn (string $style) => is_file(public_path("fonts/Li Ador Noirrit {$style}.ttf"))))
        @if ($banglaFaces->isNotEmpty())
            <style>
                @foreach ($banglaFaces as $weight => $style)
                    @font-face {
                        font-family: 'Li Ador Noirrit';
                        src: url('{{ asset('fonts/'.rawurlencode("Li Ador Noirrit {$style}.ttf")) }}') format('truetype');
                        font-weight: {{ $weight }};
                        font-style: normal;
                        font-display: swap;
                        unicode-range: U+0964-0965, U+0980-09FF, U+200C-200D, U+25CC;
                    }
                @endforeach
            </style>
        @endif

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>

            {{--
                One browser icon, from the shared branding contract. Inside the
                head slot because this is the fallback for when SSR is not
                running: with SSR, the rendered head carries the same keyed link,
                and rendering both would put two icons on the page. `data-inertia`
                lets the client-side <Head> adopt this element by its `favicon`
                key, so navigating never stacks a second link beside it.
            --}}
            @php($branding = $page['props']['branding'] ?? [])
            <link rel="icon" href="{{ $branding['favicon_url'] ?? '/favicon.svg' }}" type="{{ $branding['favicon_type'] ?? 'image/svg+xml' }}" data-inertia="favicon">
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
