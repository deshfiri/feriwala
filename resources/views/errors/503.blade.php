<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- Checks again on its own, so nobody has to remember to come back. --}}
    <meta http-equiv="refresh" content="60">

    <title>{{ config('app.name', 'Banij') }} | Under maintenance</title>

    {{--
    This page must remain independent from Vite, database connections,
    external fonts, images and other application services: it is shown exactly
    when those may not be there. Everything it needs is inline.
    --}}

    <style>
        :root {
            color-scheme: light dark;
            --background: #faf8f5;
            --surface: #ffffff;
            --border: #e7e2dc;
            --text: #1c1917;
            --muted: #6b645d;
            --brand: #ca6330;
            --brand-soft: rgba(202, 99, 48, 0.1);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #151210;
                --surface: #1f1b18;
                --border: #332d29;
                --text: #f5f1ed;
                --muted: #a8a099;
                --brand: #e8814a;
                --brand-soft: rgba(232, 129, 74, 0.14);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 1.5rem 1rem;
            background: var(--background);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        main {
            width: 100%;
            max-width: 26rem;
            text-align: center;
        }

        .brand {
            margin: 0 0 1.5rem;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--brand);
        }

        .card {
            padding: 2.25rem 1.75rem 2rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04), 0 8px 24px rgba(0, 0, 0, 0.05);
        }

        .icon {
            display: grid;
            place-items: center;
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1.25rem;
            border-radius: 999px;
            background: var(--brand-soft);
            color: var(--brand);
        }

        .icon svg {
            width: 1.75rem;
            height: 1.75rem;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.375rem;
            font-weight: 700;
            letter-spacing: -0.015em;
        }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 0.9375rem;
        }

        .bn {
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border);
        }

        .bn h2 {
            margin: 0 0 0.25rem;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
        }

        .note {
            margin-top: 1.25rem;
            font-size: 0.8125rem;
            color: var(--muted);
        }

        @media (prefers-reduced-motion: no-preference) {
            .icon svg {
                animation: turn 12s linear infinite;
            }

            @keyframes turn {
                to {
                    transform: rotate(360deg);
                }
            }
        }
    </style>
</head>

<body>
    <main role="main">
        <p class="brand">{{ config('app.name', 'Banij') }}</p>

        <section class="card" aria-labelledby="maintenance-title">
            <div class="icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                    stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3" />
                    <path
                        d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1.03-1.56V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.56 1.03H21a2 2 0 1 1 0 4h-.09A1.7 1.7 0 0 0 19.4 15z" />
                </svg>
            </div>

            <h1 id="maintenance-title">We'll be right back</h1>
            <p>{{ config('app.name', 'Banij') }} is down for scheduled maintenance. Your data is safe, and everything
                will be available again shortly.</p>

            <div class="bn" lang="bn">
                <h2>আমরা শিগগিরই ফিরে আসছি</h2>
                <p>নির্ধারিত রক্ষণাবেক্ষণের জন্য {{ config('app.name', 'Banij') }} সাময়িকভাবে বন্ধ আছে। আপনার তথ্য নিরাপদ, কিছুক্ষণের মধ্যেই
                    সব আবার চালু হবে।</p>
            </div>

            <p class="note">This page refreshes by itself every minute. · পেজটি প্রতি মিনিটে নিজে থেকেই রিফ্রেশ হয়।</p>
        </section>
    </main>
</body>

</html>
