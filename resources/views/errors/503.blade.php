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
            --background: #fbf7f2;
            --text: #1c1917;
            --muted: #6b645d;
            --brand: #ca6330;
            --brand-2: #f0a15e;
            --glow-1: rgba(240, 161, 94, 0.35);
            --glow-2: rgba(202, 99, 48, 0.18);
            --track: rgba(202, 99, 48, 0.14);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #14110f;
                --text: #f6f2ee;
                --muted: #aaa29b;
                --brand: #ea8650;
                --brand-2: #f3b27a;
                --glow-1: rgba(234, 134, 80, 0.22);
                --glow-2: rgba(243, 178, 122, 0.1);
                --track: rgba(234, 134, 80, 0.2);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            position: relative;
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 2rem 1.25rem;
            background: var(--background);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        /* Two soft washes of colour, so the page has depth without a box. */
        .bg {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .bg::before,
        .bg::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            pointer-events: none;
        }

        .bg::before {
            top: -12rem;
            left: 50%;
            width: 34rem;
            height: 34rem;
            margin-left: -22rem;
            background: var(--glow-1);
        }

        .bg::after {
            bottom: -14rem;
            left: 50%;
            width: 30rem;
            height: 30rem;
            margin-left: -4rem;
            background: var(--glow-2);
        }

        main {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 34rem;
            text-align: center;
        }

        .brand {
            margin: 0 0 2.25rem;
            font-size: 1.625rem;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: var(--brand);
        }

        .art {
            width: 15rem;
            height: auto;
            margin: 0 auto 1.75rem;
            color: var(--brand);
            overflow: visible;
        }

        h1 {
            margin: 0 0 0.75rem;
            font-size: clamp(1.875rem, 6vw, 2.5rem);
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.03em;
        }

        .lead {
            margin: 0 auto;
            max-width: 28rem;
            color: var(--muted);
            font-size: 1.0625rem;
        }

        .bn {
            margin: 1.5rem auto 0;
            max-width: 28rem;
        }

        .bn h2 {
            margin: 0 0 0.25rem;
            font-size: 1.125rem;
            font-weight: 700;
        }

        .bn p {
            margin: 0;
            color: var(--muted);
            font-size: 0.9375rem;
        }

        .progress {
            position: relative;
            width: 12rem;
            height: 0.25rem;
            margin: 2.25rem auto 0.875rem;
            overflow: hidden;
            border-radius: 999px;
            background: var(--track);
        }

        .progress span {
            position: absolute;
            inset: 0 auto 0 0;
            width: 40%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--brand), var(--brand-2));
        }

        .note {
            margin: 0;
            color: var(--muted);
            font-size: 0.8125rem;
        }

        @media (prefers-reduced-motion: no-preference) {
            .gear-big {
                transform-origin: 74px 78px;
                animation: turn 14s linear infinite;
            }

            .gear-small {
                transform-origin: 128px 52px;
                animation: turn 9s linear infinite reverse;
            }

            .progress span {
                animation: slide 1.8s ease-in-out infinite;
            }

            @keyframes turn {
                to {
                    transform: rotate(360deg);
                }
            }

            @keyframes slide {
                0% {
                    transform: translateX(-100%);
                }

                100% {
                    transform: translateX(260%);
                }
            }
        }
    </style>
</head>

<body>
    <div class="bg" aria-hidden="true"></div>
    <main role="main" aria-labelledby="maintenance-title">
        <p class="brand">{{ config('app.name', 'Banij') }}</p>

        <svg class="art" viewBox="0 0 176 132" fill="none" aria-hidden="true">
            <ellipse cx="88" cy="118" rx="62" ry="8" fill="currentColor" opacity="0.12" />
            <g class="gear-big" stroke="currentColor" stroke-width="5" stroke-linejoin="round">
                <circle cx="74" cy="78" r="22" fill="currentColor" fill-opacity="0.12" />
                <circle cx="74" cy="78" r="8" />
                <path
                    d="M74 46v8M74 102v8M42 78h8M98 78h8M51.4 55.4l5.7 5.7M90.9 94.9l5.7 5.7M51.4 100.6l5.7-5.7M90.9 61.1l5.7-5.7"
                    stroke-linecap="round" />
            </g>
            <g class="gear-small" stroke="currentColor" stroke-width="4.5" stroke-linejoin="round" opacity="0.7">
                <circle cx="128" cy="52" r="14" fill="currentColor" fill-opacity="0.12" />
                <circle cx="128" cy="52" r="5" />
                <path d="M128 31v6M128 67v6M107 52h6M143 52h6M113.2 37.2l4.2 4.2M138.6 62.6l4.2 4.2M113.2 66.8l4.2-4.2M138.6 41.4l4.2-4.2"
                    stroke-linecap="round" />
            </g>
        </svg>

        <h1 id="maintenance-title">We'll be right back</h1>
        <p class="lead">{{ config('app.name', 'Banij') }} is getting a little better. We're making scheduled
            improvements — your data is safe and everything will be back shortly.</p>

        <div class="bn" lang="bn">
            <h2>আমরা শিগগিরই ফিরে আসছি</h2>
            <p>নির্ধারিত উন্নয়নের কাজ চলছে। আপনার তথ্য নিরাপদ, কিছুক্ষণের মধ্যেই সব আবার চালু হবে।</p>
        </div>

        <div class="progress" role="presentation"><span></span></div>
        <p class="note">Refreshing automatically · নিজে থেকেই রিফ্রেশ হচ্ছে</p>
    </main>
</body>

</html>
