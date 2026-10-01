<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ config('app.name', 'Feriwala') }} — Under Maintenance</title>

        {{--
            This view is Laravel's documented customization point for maintenance
            mode (resources/views/errors/503.blade.php) and, when `php artisan down`
            is run with `--render="errors::503"`, gets pre-rendered to static HTML
            before most of the framework boots. It must never depend on @vite,
            the database, or any other app service — all of those may be exactly
            what is down.
        --}}
        <style>
            :root {
                color-scheme: light dark;
                --bg: oklch(0.976 0.002 75);
                --surface: oklch(1 0 0);
                --border: oklch(0.89 0.004 75);
                --text: oklch(0.2 0.01 60);
                --muted: oklch(0.46 0.01 60);
                --brand: #ca6330;
                --brand-on: #ffffff;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: oklch(0.16 0.003 60);
                    --surface: oklch(0.21 0.004 60);
                    --border: oklch(0.3 0.006 60);
                    --text: oklch(0.95 0.002 75);
                    --muted: oklch(0.7 0.006 75);
                }
            }

            * {
                box-sizing: border-box;
            }

            html, body {
                height: 100%;
            }

            body {
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 1.5rem;
                background: var(--bg);
                color: var(--text);
                font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            }

            .card {
                width: 100%;
                max-width: 28rem;
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 0.75rem;
                padding: 2.5rem 2rem;
                text-align: center;
                box-shadow: 0 1px 3px oklch(0 0 0 / 0.08);
            }

            .badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 3rem;
                height: 3rem;
                border-radius: 9999px;
                background: var(--brand);
                color: var(--brand-on);
                margin-bottom: 1.25rem;
            }

            .badge svg {
                width: 1.5rem;
                height: 1.5rem;
            }

            h1 {
                margin: 0 0 0.5rem;
                font-size: 1.25rem;
                font-weight: 600;
            }

            p {
                margin: 0;
                color: var(--muted);
                font-size: 0.9375rem;
                line-height: 1.5;
            }

            p + p {
                margin-top: 0.5rem;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 6v6l4 2" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
            </span>

            <h1>{{ config('app.name', 'Feriwala') }} is down for maintenance</h1>

            <p>We're making some scheduled improvements. We'll be back shortly — please check again in a few minutes.</p>

            @if (isset($retryAfter))
                <p>Expected back in about {{ $retryAfter }} seconds.</p>
            @endif
        </div>
    </body>
</html>
