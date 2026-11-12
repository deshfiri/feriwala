/*
 * Shared shell for the developer documentation.
 *
 * Every page is plain semantic HTML inside <main id="doc"> and loads this file
 * right after the Tailwind CDN script. This file draws the header, the sidebar,
 * the on-page contents, the search palette and the previous/next links, and
 * styles the prose, so a page only has to carry its content. The page list
 * below is the only place a page is registered.
 *
 * Works from file:// as well as from any web server: every link is built
 * relative to this script's own location.
 */
(function () {
    'use strict';

    // Theme is decided before first paint so there is no flash.
    var THEME_KEY = 'banij-docs-theme';
    var storedTheme = null;

    try {
        storedTheme = localStorage.getItem(THEME_KEY);
    } catch (e) {
        storedTheme = null;
    }

    function systemDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function applyTheme(mode) {
        document.documentElement.classList.toggle('dark', mode === 'dark');
    }

    applyTheme(storedTheme || (systemDark() ? 'dark' : 'light'));

    // Page-load splash: a spinner and "Banij" typed out letter by letter.
    // Plain CSS on purpose, so it paints before the Tailwind CDN has run.
    var loaderStart = Date.now();
    var loaderEl = null;
    var loaderTimer = null;
    var WORD = 'Banij';
    var LOADER_MIN_MS = 550;

    (function () {
        var css = document.createElement('style');
        css.textContent =
            '#banij-loader{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;background:#fafaf9;transition:opacity .25s ease,visibility .25s ease}' +
            'html.dark #banij-loader{background:#0b0d12}' +
            '#banij-loader.done{opacity:0;visibility:hidden}' +
            '#banij-loader .ring{width:56px;height:56px;border-radius:50%;border:3px solid rgba(99,102,241,.18);border-top-color:#6366f1;border-right-color:#a855f7;animation:banij-spin .8s linear infinite}' +
            '#banij-loader .word{font:800 44px/1 "Plus Jakarta Sans",Inter,system-ui,sans-serif;letter-spacing:-.03em;color:#0f172a;min-height:44px;display:flex;align-items:center}' +
            'html.dark #banij-loader .word{color:#fff}' +
            '#banij-loader .word i{display:inline-block;width:3px;height:40px;margin-left:4px;background:#6366f1;animation:banij-blink .7s steps(1) infinite}' +
            '@keyframes banij-spin{to{transform:rotate(360deg)}}' +
            '@keyframes banij-blink{50%{opacity:0}}' +
            '@media (prefers-reduced-motion:reduce){#banij-loader .ring{animation-duration:2.4s}}' +
            'html.banij-loading body>*:not(#banij-loader){visibility:hidden}';
        document.head.appendChild(css);
        document.documentElement.classList.add('banij-loading');

        loaderEl = document.createElement('div');
        loaderEl.id = 'banij-loader';
        loaderEl.setAttribute('role', 'status');
        loaderEl.setAttribute('aria-label', 'Loading documentation');
        loaderEl.innerHTML = '<div class="ring"></div><div class="word"><span></span><i></i></div>';
        document.documentElement.appendChild(loaderEl);

        var typed = 0;
        var target = loaderEl.querySelector('.word span');

        loaderTimer = setInterval(function () {
            typed += 1;
            target.textContent = WORD.slice(0, typed);

            if (typed >= WORD.length) {
                clearInterval(loaderTimer);
            }
        }, 70);
    })();

    function finishLoader() {
        var wait = Math.max(0, LOADER_MIN_MS - (Date.now() - loaderStart));

        setTimeout(function () {
            document.documentElement.classList.remove('banij-loading');

            if (loaderEl) {
                loaderEl.classList.add('done');
                setTimeout(function () {
                    loaderEl.remove();
                }, 350);
            }
        }, wait);
    }

    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                    display: ['Plus Jakarta Sans', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
                },
            },
        },
    };

    /** [path, title] pairs, in reading order. */
    var NAV = [
        {
            group: 'Start here',
            items: [
                ['index.html', 'Overview'],
                ['guide/getting-started.html', 'Getting started'],
                ['guide/architecture.html', 'Architecture'],
                ['guide/conventions.html', 'Conventions & non-negotiables'],
                ['guide/glossary.html', 'Glossary'],
                ['guide/decisions.html', 'Decision log'],
            ],
        },
        {
            group: 'Platform guide',
            items: [
                ['guide/http-layers.html', 'Routing & HTTP layers'],
                ['guide/frontend.html', 'Frontend (Inertia + React)'],
                ['guide/database.html', 'Database & data model'],
                ['guide/testing.html', 'Testing & quality checks'],
                ['guide/operations.html', 'Operations, queues & scheduler'],
                ['guide/commands.html', 'Console commands'],
            ],
        },
        {
            group: 'Identity & access',
            items: [
                ['modules/account.html', 'Account'],
                ['modules/access.html', 'Access (roles & permissions)'],
                ['modules/kyc.html', 'KYC'],
                ['modules/audit.html', 'Audit'],
                ['modules/settings.html', 'Settings'],
            ],
        },
        {
            group: 'Reference data',
            items: [
                ['modules/location.html', 'Location'],
                ['modules/address.html', 'Address'],
                ['modules/bank.html', 'Bank'],
                ['modules/tax.html', 'Tax'],
            ],
        },
        {
            group: 'Money',
            items: [
                ['modules/wallet.html', 'Wallet'],
                ['modules/billing.html', 'Billing'],
                ['modules/withdrawal.html', 'Withdrawal'],
                ['modules/payout.html', 'Payout'],
                ['modules/referral.html', 'Referral'],
            ],
        },
        {
            group: 'Catalog & stock',
            items: [
                ['modules/catalog.html', 'Catalog'],
                ['modules/inventory.html', 'Inventory'],
                ['modules/sourcing.html', 'Sourcing'],
                ['modules/wholesale.html', 'Wholesale'],
                ['modules/content-library.html', 'Content Library'],
                ['modules/damage.html', 'Damage'],
            ],
        },
        {
            group: 'Orders & delivery',
            items: [
                ['modules/order.html', 'Order'],
                ['modules/courier.html', 'Courier'],
                ['modules/cases.html', 'Cases'],
                ['modules/notification.html', 'Notification'],
            ],
        },
        {
            group: 'Suppliers & packages',
            items: [
                ['modules/supplier.html', 'Supplier'],
                ['modules/package.html', 'Package'],
                ['modules/storage.html', 'Storage'],
            ],
        },
        {
            group: 'Sales channels',
            items: [
                ['modules/website.html', 'Website'],
                ['modules/external-store.html', 'External Store'],
            ],
        },
        {
            group: 'Integrations & APIs',
            items: [
                ['integrations/overview.html', 'Integrations (payment, SMS, courier, store, DNS)'],
                ['api/mobile-api.html', 'Mobile API'],
                ['api/storefront-api.html', 'Storefront API'],
                ['storefront-app.html', 'Storefront application'],
            ],
        },
    ];

    var script = document.currentScript;
    var base = script.src.replace(/assets\/docs\.js(\?.*)?$/, '');
    var here = location.href.split('#')[0].split('?')[0];
    var flat = [];

    NAV.forEach(function (g) {
        g.items.forEach(function (i) {
            flat.push({ href: base + i[0], title: i[1], group: g.group, path: i[0] });
        });
    });

    var current = -1;
    flat.forEach(function (p, i) {
        if (here === p.href || here === p.href.replace(/index\.html$/, '')) {
            current = i;
        }
    });

    function esc(s) {
        return s.replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function slug(text) {
        return (
            text
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'section'
        );
    }

    var fonts = document.createElement('link');
    fonts.rel = 'stylesheet';
    fonts.href =
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap';
    document.head.appendChild(fonts);

    var style = document.createElement('style');
    style.type = 'text/tailwindcss';
    style.textContent = [
        '@layer base {',
        '  html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; }',
        '  body { @apply bg-[#fafaf9] dark:bg-[#0b0d12] font-sans; }',
        '  ::selection { background: rgba(99,102,241,.25); }',
        '}',
        '@layer components {',
        /* ---- prose ---- */
        '  .doc { @apply min-w-0 text-[1.0625rem] leading-[1.9] text-slate-600 dark:text-slate-300; overflow-wrap: break-word; counter-reset: sec; }',
        '  .doc h1 { @apply font-display text-4xl sm:text-5xl font-extrabold tracking-tight leading-[1.1] text-slate-900 dark:text-white mb-6; }',
        '  .doc h1 .grad { @apply bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-500 bg-clip-text text-transparent; }',
        '  .doc .lead { @apply text-xl leading-9 text-slate-500 dark:text-slate-400 mb-4 max-w-3xl; }',
        '  .doc h2 { @apply font-display text-[1.75rem] sm:text-[2rem] font-bold tracking-tight leading-tight text-slate-900 dark:text-white mt-24 mb-6 pt-10 scroll-mt-24 border-t border-slate-200 dark:border-slate-800/80; counter-increment: sec; }',
        '  .doc h2::before { content: counter(sec, decimal-leading-zero); @apply mb-3 block font-mono text-xs font-medium tracking-[0.2em] text-indigo-500 dark:text-indigo-400; }',
        '  .doc h3 { @apply font-display text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100 mt-12 mb-3 scroll-mt-24; }',
        '  .doc h4 { @apply text-xs font-semibold uppercase tracking-[0.14em] text-slate-400 mt-8 mb-2; }',
        '  .doc .anchor { @apply ml-3 font-sans text-base font-normal text-slate-300 dark:text-slate-600 no-underline opacity-0 transition hover:text-indigo-500 focus:opacity-100; }',
        '  .doc h2:hover .anchor, .doc h3:hover .anchor { @apply opacity-100; }',
        '  .doc p { @apply my-5; }',
        '  .doc strong { @apply font-semibold text-slate-900 dark:text-slate-100; }',
        '  .doc ul { @apply my-6 space-y-2.5; }',
        '  .doc ul > li { @apply relative pl-7; }',
        '  .doc ul > li::before { content: ""; @apply absolute left-1.5 top-[0.8em] h-1.5 w-1.5 rotate-45 rounded-[2px] bg-indigo-500/80; }',
        '  .doc ol { @apply my-6 ml-6 list-decimal space-y-2.5 marker:font-mono marker:text-sm marker:font-medium marker:text-indigo-500; }',
        '  .doc ol > li { @apply pl-2; }',
        '  .doc li > ul, .doc li > ol { @apply my-2; }',
        '  .doc a { @apply text-indigo-600 dark:text-indigo-400 font-medium underline decoration-indigo-300/50 decoration-2 underline-offset-4 transition hover:decoration-indigo-500; }',
        '  .doc code { @apply font-mono text-[0.82em] bg-indigo-50/70 dark:bg-indigo-400/10 text-indigo-700 dark:text-indigo-200 border border-indigo-100 dark:border-indigo-400/20 rounded-md px-1.5 py-[0.15em]; overflow-wrap: anywhere; }',
        '  .doc a code { @apply text-indigo-700 dark:text-indigo-300; }',
        /* ---- code blocks ---- */
        '  .codeblock { @apply relative my-8 overflow-hidden rounded-2xl bg-[#0d1117] ring-1 ring-slate-800 shadow-xl shadow-slate-900/10; }',
        '  .codeblock::before { content: ""; display: block; height: 2.25rem; border-bottom: 1px solid #21262d; background: radial-gradient(circle at 18px 50%, #ff5f56 5px, transparent 6px), radial-gradient(circle at 38px 50%, #ffbd2e 5px, transparent 6px), radial-gradient(circle at 58px 50%, #27c93f 5px, transparent 6px), #151a22; }',
        '  .codeblock pre { @apply m-0 overflow-x-auto rounded-none p-5 text-[13.5px] leading-7 text-slate-200 ring-0; }',
        '  .doc pre { @apply my-8 overflow-x-auto rounded-2xl bg-[#0d1117] ring-1 ring-slate-800 p-5 text-[13.5px] leading-7 text-slate-200; }',
        '  .doc pre code { @apply bg-transparent border-0 text-inherit p-0 rounded-none text-[13.5px]; overflow-wrap: normal; }',
        '  .copy { @apply absolute right-3 top-1.5 rounded-md border border-slate-700 bg-slate-800/80 px-2.5 py-1 text-[11px] font-medium text-slate-300 transition hover:bg-slate-700 hover:text-white; }',
        /* ---- tables ---- */
        '  .table-wrap { @apply my-8 max-w-full overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/40 shadow-sm; }',
        '  .table-wrap > table { @apply my-0 w-full min-w-full border-collapse text-left text-[14.5px] leading-7; }',
        '  .doc th { @apply whitespace-nowrap bg-slate-50 dark:bg-slate-900 px-5 py-3.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800; }',
        '  .doc td { @apply align-top px-5 py-3.5 border-b border-slate-100 dark:border-slate-800/70; }',
        '  .doc tbody tr:last-child td { @apply border-b-0; }',
        '  .doc tbody tr:hover td { @apply bg-indigo-50/40 dark:bg-indigo-400/5; }',
        '  .doc td:first-child { @apply font-medium text-slate-900 dark:text-slate-100; }',
        '  .doc blockquote { @apply my-8 border-l-4 border-indigo-400 bg-white dark:bg-slate-900/60 rounded-r-2xl py-4 pl-6 pr-5 text-lg text-slate-600 dark:text-slate-300 shadow-sm; }',
        '  .doc hr { @apply my-16 border-slate-200 dark:border-slate-800; }',
        '  .doc dl { @apply my-8 grid overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/40 shadow-sm md:grid-cols-[16rem_1fr]; }',
        '  .doc dt { @apply bg-slate-50 dark:bg-slate-900 px-5 pt-4 pb-1 font-semibold text-slate-900 dark:text-slate-100 md:border-b md:border-slate-100 md:dark:border-slate-800/70 md:py-4; }',
        '  .doc dd { @apply px-5 pb-4 md:py-4 border-b border-slate-100 dark:border-slate-800/70; }',
        '  .doc dt:last-of-type, .doc dd:last-of-type { @apply border-b-0; }',
        /* ---- callouts ---- */
        '  .callout { @apply relative my-8 overflow-hidden rounded-2xl border py-5 pl-7 pr-6 text-base leading-8 shadow-sm; }',
        '  .callout::after { content: ""; @apply absolute inset-y-0 left-0 w-1.5; background: currentColor; opacity: .55; }',
        '  .callout::before { @apply mb-2 block font-mono text-[11px] font-bold uppercase tracking-[0.18em] opacity-80; }',
        '  .callout > :first-child { @apply mt-0; }',
        '  .callout > :last-child { @apply mb-0; }',
        '  .callout p { @apply my-2; }',
        '  .callout code { @apply bg-white/60 dark:bg-black/20 border-transparent text-inherit; }',
        '  .callout strong, .callout a { @apply text-inherit; }',
        '  .callout-note { @apply border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-100; }',
        '  .callout-note::before { content: "Note"; }',
        '  .callout-warn { @apply border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100; }',
        '  .callout-warn::before { content: "Watch out"; }',
        '  .callout-rule { @apply border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-100; }',
        '  .callout-rule::before { content: "Rule"; }',
        '  .callout-tip { @apply border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100; }',
        '  .callout-tip::before { content: "Tip"; }',
        '  .badge { @apply inline-block rounded-full bg-indigo-50 dark:bg-indigo-400/10 px-2.5 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300; }',
        /* ---- cards ---- */
        '  .cards { @apply my-8 grid gap-5 sm:grid-cols-2 xl:grid-cols-3; }',
        '  .card { @apply relative overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/50 p-6 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-indigo-400 hover:shadow-xl hover:shadow-indigo-500/10 dark:hover:border-indigo-500; }',
        '  .card::after { content: "→"; @apply absolute bottom-5 right-5 text-lg text-slate-300 transition duration-200 dark:text-slate-600; }',
        '  .card:hover::after { @apply translate-x-1 text-indigo-500; }',
        '  .doc a.card { @apply block no-underline font-normal text-slate-600 dark:text-slate-300; }',
        '  .card h3 { @apply mt-0 mb-2 text-lg; }',
        '  .card p { @apply my-0 pr-8 text-[15px] leading-7 text-slate-500 dark:text-slate-400; }',
        '  .doc img { @apply my-8 max-w-full rounded-2xl border border-slate-200 dark:border-slate-800 shadow-lg; }',
        /* ---- hero (overview page) ---- */
        '  .hero { @apply relative -mx-5 sm:mx-0 mb-4 overflow-hidden rounded-none sm:rounded-3xl border-y sm:border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/60 px-6 py-14 sm:px-12 sm:py-20 shadow-xl shadow-indigo-500/5; }',
        '  .hero::before { content: ""; @apply pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-gradient-to-br from-indigo-400/30 via-violet-400/20 to-fuchsia-400/30 blur-3xl; }',
        '  .hero::after { content: ""; @apply pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-gradient-to-tr from-sky-400/20 to-emerald-300/20 blur-3xl; }',
        '  .hero > * { @apply relative z-10; }',
        '  .hero .eyebrow { @apply mb-5 inline-flex items-center gap-2 rounded-full border border-indigo-200 dark:border-indigo-400/30 bg-indigo-50 dark:bg-indigo-400/10 px-3.5 py-1 font-mono text-xs font-medium tracking-wider text-indigo-700 dark:text-indigo-300; }',
        '  .hero h1 { @apply text-5xl sm:text-6xl mb-6; }',
        '  .hero .actions { @apply mt-8 flex flex-wrap gap-3; }',
        '  .doc a.btn { @apply inline-flex items-center gap-2 rounded-xl bg-slate-900 dark:bg-white px-5 py-3 text-[15px] font-semibold text-white dark:text-slate-900 no-underline shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl; }',
        '  .doc a.btn-ghost { @apply bg-white dark:bg-slate-800 text-slate-900 dark:text-white ring-1 ring-slate-200 dark:ring-slate-700 shadow-sm; }',
        '  .stats { @apply mt-10 grid grid-cols-2 gap-3 sm:grid-cols-4; }',
        '  .stat { @apply rounded-2xl border border-slate-200 dark:border-slate-800 bg-white/70 dark:bg-slate-950/40 px-5 py-4 backdrop-blur; }',
        '  .stat b { @apply block font-display text-3xl font-extrabold leading-tight text-slate-900 dark:text-white; }',
        '  .stat span { @apply block text-sm leading-5 text-slate-500 dark:text-slate-400; }',
        '  .section-intro { @apply -mt-2 mb-2 max-w-2xl text-lg text-slate-500 dark:text-slate-400; }',
        /* ---- palette ---- */
        '  .pal-item { @apply flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-[15px] text-slate-700 dark:text-slate-200; }',
        '  .pal-item[aria-selected=true] { @apply bg-indigo-50 dark:bg-indigo-400/10 text-indigo-700 dark:text-indigo-200; }',
        '}',
    ].join('\n');
    document.head.appendChild(style);

    function build() {
        try {
            buildPage();
        } finally {
            finishLoader();
        }
    }

    function buildPage() {
        var source = document.getElementById('doc');

        if (!source) {
            return;
        }

        var titleEl = source.querySelector('h1');
        var title = (titleEl || {}).textContent || document.title;
        document.title = /^Banij/.test(title) ? title : title + ' · Banij developer docs';

        var headings = [].slice.call(source.querySelectorAll('h2, h3')).filter(function (h) {
            return !h.closest('.cards');
        });
        var used = {};

        headings.forEach(function (h) {
            if (!h.id) {
                var id = slug(h.textContent);
                var n = 2;
                var candidate = id;

                while (used[candidate] || document.getElementById(candidate)) {
                    candidate = id + '-' + n++;
                }

                h.id = candidate;
            }

            used[h.id] = true;
        });

        var navHtml = NAV.map(function (g) {
            var items = g.items
                .map(function (i) {
                    var href = base + i[0];
                    var active = href === here || href.replace(/index\.html$/, '') === here;

                    return (
                        '<li><a data-nav href="' +
                        href +
                        '" class="relative block rounded-xl px-3.5 py-2 text-[14px] transition ' +
                        (active
                            ? 'bg-indigo-50 dark:bg-indigo-400/10 text-indigo-700 dark:text-indigo-300 font-semibold'
                            : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/70 hover:text-slate-900 dark:hover:text-slate-100') +
                        '">' +
                        esc(i[1]) +
                        '</a></li>'
                    );
                })
                .join('');

            return (
                '<div data-group class="mb-8"><p class="mb-2 px-3.5 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">' +
                esc(g.group) +
                '</p><ul class="space-y-0.5">' +
                items +
                '</ul></div>'
            );
        }).join('');

        var toc = headings
            .map(function (h) {
                return (
                    '<li><a href="#' +
                    h.id +
                    '" data-toc class="block border-l-2 border-slate-200 py-1.5 pl-4 text-[13px] leading-5 text-slate-500 transition hover:border-slate-400 hover:text-slate-900 dark:border-slate-800 dark:text-slate-400 dark:hover:text-slate-100 ' +
                    (h.tagName === 'H3' ? 'pl-7 ' : '') +
                    '">' +
                    esc(h.textContent) +
                    '</a></li>'
                );
            })
            .join('');

        var prev = current > 0 ? flat[current - 1] : null;
        var next = current >= 0 && current < flat.length - 1 ? flat[current + 1] : null;

        var cardCls =
            'group block rounded-2xl border border-slate-200 bg-white p-6 no-underline shadow-sm transition hover:-translate-y-1 hover:border-indigo-400 hover:shadow-xl hover:shadow-indigo-500/10 dark:border-slate-800 dark:bg-slate-900/50';

        var pager =
            '<nav class="mt-24 grid gap-5 sm:grid-cols-2">' +
            (prev
                ? '<a href="' + prev.href + '" class="' + cardCls + '"><span class="block font-mono text-[11px] uppercase tracking-[0.18em] text-slate-400">← Previous</span><span class="mt-1 block font-display text-lg font-bold text-slate-900 dark:text-white">' + esc(prev.title) + '</span></a>'
                : '<span></span>') +
            (next
                ? '<a href="' + next.href + '" class="' + cardCls + ' text-right"><span class="block font-mono text-[11px] uppercase tracking-[0.18em] text-slate-400">Next →</span><span class="mt-1 block font-display text-lg font-bold text-slate-900 dark:text-white">' + esc(next.title) + '</span></a>'
                : '<span></span>') +
            '</nav>';

        var iconBtn =
            'rounded-xl p-2.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white';

        var shell = document.createElement('div');
        shell.className = 'relative min-h-screen font-sans';
        shell.innerHTML =
            '<div id="docs-progress" class="fixed left-0 top-0 z-50 h-[3px] w-0 bg-gradient-to-r from-indigo-500 via-violet-500 to-fuchsia-500"></div>' +
            '<div class="pointer-events-none absolute inset-x-0 top-0 -z-0 h-[32rem] bg-[radial-gradient(60rem_24rem_at_50%_-8rem,rgba(99,102,241,.14),transparent)] dark:bg-[radial-gradient(60rem_24rem_at_50%_-8rem,rgba(99,102,241,.22),transparent)]"></div>' +
            '<header class="sticky top-0 z-40 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-[#fafaf9]/80 px-4 backdrop-blur-xl dark:border-slate-800/80 dark:bg-[#0b0d12]/80 sm:px-6">' +
            '<button id="docs-menu" type="button" aria-label="Menu" class="' + iconBtn + ' lg:hidden">' +
            '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg></button>' +
            '<a href="' + base + 'index.html" class="flex items-center gap-3 no-underline">' +
            '<span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 via-violet-600 to-fuchsia-500 font-display text-base font-extrabold text-white shadow-lg shadow-indigo-500/30">B</span>' +
            '<span class="font-display text-lg font-extrabold tracking-tight text-slate-900 dark:text-white">Banij <span class="font-medium text-slate-400">docs</span></span></a>' +
            '<div class="flex-1"></div>' +
            '<button id="docs-search" type="button" class="hidden items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-400 shadow-sm transition hover:border-indigo-400 dark:border-slate-700 dark:bg-slate-900 sm:flex sm:w-72">' +
            '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>' +
            '<span class="flex-1 text-left">Search the docs…</span>' +
            '<kbd class="rounded-md border border-slate-200 px-1.5 py-0.5 font-mono text-[11px] dark:border-slate-700">Ctrl K</kbd></button>' +
            '<button id="docs-search-m" type="button" aria-label="Search" class="' + iconBtn + ' sm:hidden">' +
            '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg></button>' +
            '<button id="docs-theme" type="button" aria-label="Toggle theme" class="' + iconBtn + '">' +
            '<svg class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>' +
            '<svg class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></button>' +
            '</header>' +
            '<div id="docs-backdrop" class="fixed inset-0 z-20 hidden bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>' +
            '<div class="relative z-10 mx-auto flex max-w-[100rem]">' +
            '<aside id="docs-side" class="fixed inset-y-0 left-0 z-30 hidden w-80 shrink-0 overflow-y-auto border-r border-slate-200/70 bg-[#fafaf9] px-4 pb-12 pt-24 dark:border-slate-800/80 dark:bg-[#0b0d12] lg:sticky lg:top-16 lg:z-10 lg:block lg:h-[calc(100vh-4rem)] lg:pt-8">' +
            '<input id="docs-filter" type="search" placeholder="Filter pages…" class="mb-7 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-900 placeholder-slate-400 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">' +
            navHtml +
            '</aside>' +
            '<div class="min-w-0 flex-1 px-5 py-12 sm:px-10 lg:px-16 lg:py-16">' +
            '<article id="docs-article" class="doc mx-auto max-w-[52rem]"></article>' +
            '<div class="mx-auto max-w-[52rem]">' + pager +
            '<p class="mt-14 border-t border-slate-200 pt-6 text-sm leading-6 text-slate-400 dark:border-slate-800">Written from the code and the specification in <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs dark:bg-slate-800">requirements/</code>. When this page and the code disagree, the code wins — then fix the page.</p></div>' +
            '</div>' +
            (headings.length > 2
                ? '<aside class="sticky top-16 hidden h-[calc(100vh-4rem)] w-72 shrink-0 overflow-y-auto py-16 pr-8 xl:block"><p class="mb-4 pl-4 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400 dark:text-slate-500">On this page</p><ul id="docs-toc">' + toc + '</ul></aside>'
                : '') +
            '</div>' +
            '<div id="docs-pal" class="fixed inset-0 z-50 hidden items-start justify-center bg-slate-900/60 p-4 pt-[12vh] backdrop-blur-sm">' +
            '<div class="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">' +
            '<input id="docs-pal-input" type="text" autocomplete="off" placeholder="Jump to a page or a section…" class="w-full border-b border-slate-200 bg-transparent px-5 py-4 text-base text-slate-900 placeholder-slate-400 focus:outline-none dark:border-slate-800 dark:text-white">' +
            '<div id="docs-pal-list" class="max-h-[50vh] overflow-y-auto p-2"></div>' +
            '</div></div>';

        var article = shell.querySelector('#docs-article');

        while (source.firstChild) {
            article.appendChild(source.firstChild);
        }

        source.replaceWith(shell);

        // Breadcrumb and reading time above/below the page title (not on the hero).
        var h1 = article.querySelector('h1');

        if (h1 && h1.parentNode === article && current >= 0) {
            var words = (article.textContent || '').trim().split(/\s+/).length;
            var minutes = Math.max(1, Math.round(words / 200));

            var crumb = document.createElement('p');
            crumb.className =
                '!mb-5 !mt-0 flex flex-wrap items-center gap-2 font-mono text-xs uppercase tracking-[0.16em] text-indigo-500 dark:text-indigo-400';
            crumb.innerHTML = '<span>' + esc(flat[current].group) + '</span><span class="text-slate-300 dark:text-slate-600">/</span><span class="text-slate-400">' + minutes + ' min read</span>';
            article.insertBefore(crumb, h1);
        }

        var side = document.getElementById('docs-side');
        var backdrop = document.getElementById('docs-backdrop');

        function toggleMenu(open) {
            side.classList.toggle('hidden', !open);
            backdrop.classList.toggle('hidden', !open);
        }

        document.getElementById('docs-menu').addEventListener('click', function () {
            toggleMenu(side.classList.contains('hidden'));
        });
        backdrop.addEventListener('click', function () {
            toggleMenu(false);
        });

        document.getElementById('docs-theme').addEventListener('click', function () {
            var next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
            applyTheme(next);

            try {
                localStorage.setItem(THEME_KEY, next);
            } catch (e) {
                /* private mode: the choice just will not persist */
            }
        });

        // Tables scroll inside their own box instead of widening the page.
        [].forEach.call(article.querySelectorAll('table'), function (t) {
            var wrap = document.createElement('div');
            wrap.className = 'table-wrap';
            t.parentNode.insertBefore(wrap, t);
            wrap.appendChild(t);
        });

        // Code blocks get a window frame and a copy button.
        [].forEach.call(article.querySelectorAll('pre'), function (pre) {
            var box = document.createElement('div');
            box.className = 'codeblock';
            pre.parentNode.insertBefore(box, pre);
            box.appendChild(pre);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'copy';
            btn.textContent = 'Copy';
            btn.addEventListener('click', function () {
                var done = function () {
                    btn.textContent = 'Copied ✓';
                    setTimeout(function () {
                        btn.textContent = 'Copy';
                    }, 1500);
                };

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(pre.innerText).then(done, function () {});
                }
            });
            box.appendChild(btn);
        });

        // A link icon on each heading, to copy its address.
        [].forEach.call(article.querySelectorAll('h2, h3'), function (h) {
            if (h.closest('.cards') || !h.id) {
                return;
            }

            var a = document.createElement('a');
            a.className = 'anchor';
            a.href = '#' + h.id;
            a.setAttribute('aria-label', 'Link to this section');
            a.textContent = '#';
            h.appendChild(a);
        });

        // Reading progress.
        var bar = document.getElementById('docs-progress');

        function progress() {
            var h = document.documentElement;
            var max = h.scrollHeight - h.clientHeight;
            bar.style.width = (max > 0 ? Math.min(100, (h.scrollTop / max) * 100) : 0) + '%';
        }

        window.addEventListener('scroll', progress, { passive: true });
        progress();

        // The contents list follows the section being read.
        var tocLinks = [].slice.call(document.querySelectorAll('[data-toc]'));

        if (tocLinks.length && 'IntersectionObserver' in window) {
            var mark = function (id) {
                tocLinks.forEach(function (l) {
                    var on = l.getAttribute('href') === '#' + id;
                    l.classList.toggle('!border-indigo-500', on);
                    l.classList.toggle('!text-indigo-600', on);
                    l.classList.toggle('dark:!text-indigo-300', on);
                    l.classList.toggle('font-semibold', on);
                });
            };

            var io = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (e) {
                        if (e.isIntersecting) {
                            mark(e.target.id);
                        }
                    });
                },
                { rootMargin: '-80px 0px -70% 0px' },
            );

            headings.forEach(function (h) {
                io.observe(h);
            });
        }

        document.getElementById('docs-filter').addEventListener('input', function (e) {
            var q = e.target.value.trim().toLowerCase();

            [].forEach.call(side.querySelectorAll('[data-group]'), function (g) {
                var any = false;

                [].forEach.call(g.querySelectorAll('li'), function (li) {
                    var hit = q === '' || li.textContent.toLowerCase().indexOf(q) !== -1;
                    li.style.display = hit ? '' : 'none';
                    any = any || hit;
                });

                g.style.display = any ? '' : 'none';
            });
        });

        var active = side.querySelector('.font-semibold');

        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'center' });
        }

        // Search palette: every page, plus the sections of this one.
        var pal = document.getElementById('docs-pal');
        var palInput = document.getElementById('docs-pal-input');
        var palList = document.getElementById('docs-pal-list');
        var entries = flat
            .map(function (p) {
                return { label: p.title, hint: p.group, href: p.href };
            })
            .concat(
                headings.map(function (h) {
                    return { label: h.textContent.replace(/#$/, ''), hint: 'On this page', href: '#' + h.id };
                }),
            );
        var shown = [];
        var sel = 0;

        function paint() {
            palList.innerHTML = shown.length
                ? shown
                      .map(function (r, i) {
                          return (
                              '<button type="button" class="pal-item" data-i="' + i + '" aria-selected="' + (i === sel) + '"><span>' +
                              esc(r.label) +
                              '</span><span class="font-mono text-[11px] uppercase tracking-wider text-slate-400">' +
                              esc(r.hint) +
                              '</span></button>'
                          );
                      })
                      .join('')
                : '<p class="px-4 py-6 text-center text-sm text-slate-400">Nothing matches.</p>';
        }

        function filter() {
            var q = palInput.value.trim().toLowerCase();
            shown = entries
                .filter(function (r) {
                    return q === '' || r.label.toLowerCase().indexOf(q) !== -1 || r.hint.toLowerCase().indexOf(q) !== -1;
                })
                .slice(0, 40);
            sel = 0;
            paint();
        }

        function go(r) {
            pal.classList.add('hidden');
            pal.classList.remove('flex');

            if (r.href.charAt(0) === '#') {
                location.hash = r.href;
            } else {
                location.href = r.href;
            }
        }

        function openPal() {
            pal.classList.remove('hidden');
            pal.classList.add('flex');
            palInput.value = '';
            filter();
            palInput.focus();
        }

        function closePal() {
            pal.classList.add('hidden');
            pal.classList.remove('flex');
        }

        document.getElementById('docs-search').addEventListener('click', openPal);
        document.getElementById('docs-search-m').addEventListener('click', openPal);
        pal.addEventListener('click', function (e) {
            if (e.target === pal) {
                closePal();
            }
        });
        palList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-i]');

            if (b) {
                go(shown[+b.getAttribute('data-i')]);
            }
        });
        palInput.addEventListener('input', filter);
        palInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                sel = Math.max(0, Math.min(shown.length - 1, sel + (e.key === 'ArrowDown' ? 1 : -1)));
                paint();
                var el = palList.querySelector('[aria-selected=true]');
                el && el.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && shown[sel]) {
                go(shown[sel]);
            }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openPal();
            } else if (e.key === 'Escape') {
                closePal();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
