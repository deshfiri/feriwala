<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ config('app.name', 'Banij') }} | Under Maintenance</title>

    {{--
    This page must remain independent from Vite, database connections,
    external fonts and other application services.
    --}}

    <style>
        :root {
            color-scheme: light dark;
            --background: #fffaf6;
            --surface: #ffffff;
            --border: rgba(202, 99, 48, 0.16);
            --text: #121525;
            --muted: #5d606b;
            --brand: #f27622;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #161311;
                --surface: #211c19;
                --border: rgba(242, 118, 34, 0.25);
                --text: #fffaf6;
                --muted: #c9c2bd;
            }
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: clamp(1rem, 3vw, 2.5rem);
            background:
                radial-gradient(circle at top,
                    rgba(242, 118, 34, 0.1),
                    transparent 42%),
                var(--background);
            color: var(--text);
            font-family:
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                Roboto,
                Helvetica,
                Arial,
                sans-serif;
        }

        .maintenance {
            width: min(100%, 72rem);
        }

        .card {
            overflow: hidden;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: clamp(1rem, 2vw, 1.5rem);
            box-shadow:
                0 1.5rem 4rem rgba(74, 35, 14, 0.12),
                0 0.25rem 1rem rgba(74, 35, 14, 0.06);
        }

        .visual {
            position: relative;
            background: #fff8f2;
        }

        .visual img {
            display: block;
            width: 100%;
            height: 80vh;
            aspect-ratio: 3 / 2;
            object-fit: contain;
        }

        .status {
            padding: 1rem 1.5rem 1.25rem;
            text-align: center;
        }

        .retry {
            margin: 0;
            color: var(--muted);
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .retry strong {
            color: var(--brand);
            font-weight: 700;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 640px) {
            body {
                padding: 0;
                background: var(--surface);
            }

            .maintenance {
                width: 100%;
                min-height: 80vh;
                display: grid;
                place-items: center;
            }

            .card {
                width: 100%;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .visual img {
                width: 100%;
                min-height: 21rem;
                aspect-ratio: auto;
                object-fit: contain;
            }

            .status {
                padding: 0.875rem 1rem 1.5rem;
            }

            .retry {
                font-size: 0.8125rem;
            }
        }

        @media (max-width: 400px) {
            .visual img {
                min-height: 17rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="maintenance" role="main">
        <section class="card" aria-labelledby="maintenance-title">
            <div class="visual">
                <img src="/images/banij-maintanance.jpeg"
                    alt="Banij is down for maintenance. We are making scheduled improvements and will be back shortly."
                    width="1536" height="1024" fetchpriority="high">
            </div>
        </section>
    </main>
</body>

</html>