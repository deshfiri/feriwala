<!DOCTYPE html>
{{--
    The accent an administrator chose under Admin → Branding, painted before
    the stylesheet loads so the page never flashes the default first. Only
    ever `#rrggbb` values: `App\Domain\Settings\Branding` drops anything else,
    and they are escaped here regardless. `BrandingHead` keeps the same three
    properties in step after a save without a full reload.
--}}
@php($accent = $page['props']['branding']['accent'] ?? null)
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @if (is_array($accent)) style="--brand-base: {{ $accent['color'] }}; --brand-on: {{ $accent['on'] }}; --brand-lifted-on: {{ $accent['lifted_on'] }};" @endif>
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
                background-color: oklch(0.984 0.002 247);
            }

            html.dark {
                background-color: oklch(0.2 0.016 250);
            }
        </style>

        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

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
