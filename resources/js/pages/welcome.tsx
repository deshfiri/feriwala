import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import BrandingHead from '@/components/branding-head';
import { dashboard, login } from '@/routes';

const quotes = [
    'Build something people value, and growth will follow.',
    'Small steps every day build remarkable businesses.',
    'Good business starts with trust and grows with consistency.',
    'Every opportunity begins with a decision to move forward.',
    'Think long term. Build with purpose.',
    'Progress starts when ideas turn into action.',
    'Strong businesses are built on strong relationships.',
    'The best time to build your future is today.',
];

export default function Welcome() {
    const { auth } = usePage().props;
    const dashboardUrl = dashboard();

    const quote = useMemo(
        () => quotes[Math.floor(Math.random() * quotes.length)],
        [],
    );

    return (
        <>
            <Head title="Welcome" />
            <BrandingHead />

            <main className="relative min-h-screen overflow-hidden text-[#171717]">


                <div className="relative mx-auto flex min-h-screen w-full max-w-[1440px] flex-col px-6 sm:px-10 lg:px-16 xl:px-20">

                    {/* Brand */}
                    <header className="flex h-24 items-center">
                        <AppLogoIcon className="h-11 w-auto max-w-[190px]" />
                    </header>

                    {/* One Section */}
                    <div className="grid flex-1 items-center gap-16 pb-24 pt-8 lg:grid-cols-[1fr_0.85fr] lg:gap-24 lg:pb-16 lg:pt-0">

                        {/* LEFT */}
                        <section className="max-w-[620px]">

                            <div className="mb-7 flex items-center gap-3">
                                <span className="h-2 w-2 rounded-full bg-orange-500" />
                                <span className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">
                                    Business Portal
                                </span>
                            </div>

                            <h1 className="text-[44px] font-semibold leading-[1.08] tracking-[-0.045em] text-slate-950 sm:text-5xl lg:text-[64px]">
                                Everything you need
                                <span className="block text-slate-400">
                                    to move forward.
                                </span>
                            </h1>

                            <p className="mt-7 max-w-[500px] text-base leading-7 text-slate-500 sm:text-[17px]">
                                Access your account and continue managing your
                                business from one simple place.
                            </p>

                            {/* Actions */}
                            <div className="mt-10 flex flex-col gap-3 sm:flex-row">

                                {auth.user ? (
                                    <Link
                                        href={dashboardUrl}
                                        className="group inline-flex h-[52px] items-center justify-center rounded-xl bg-slate-950 px-7 text-sm font-semibold text-white shadow-sm transition-all hover:bg-slate-800"
                                    >
                                        Go to Dashboard

                                        <svg
                                            className="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1"
                                            viewBox="0 0 20 20"
                                            fill="none"
                                        >
                                            <path
                                                d="M4 10H16M11 5L16 10L11 15"
                                                stroke="currentColor"
                                                strokeWidth="1.7"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            />
                                        </svg>
                                    </Link>
                                ) : (
                                    <>
                                        {/* LOGIN */}
                                        <Link
                                            href={login()}
                                            className="group inline-flex h-[52px] items-center justify-center rounded-xl bg-slate-950 px-8 text-sm font-semibold text-white shadow-sm transition-all hover:bg-slate-800"
                                        >
                                            Login

                                            <svg
                                                className="ml-2 h-4 w-4 transition-transform group-hover:translate-x-1"
                                                viewBox="0 0 20 20"
                                                fill="none"
                                            >
                                                <path
                                                    d="M4 10H16M11 5L16 10L11 15"
                                                    stroke="currentColor"
                                                    strokeWidth="1.7"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                />
                                            </svg>
                                        </Link>

                                        {/* SUPPLIER */}
                                        <a
                                            href="/supplier/register"
                                            className="inline-flex h-[52px] items-center justify-center rounded-xl border border-slate-200 bg-white px-8 text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(0,0,0,0.03)] transition-all hover:border-slate-300 hover:bg-slate-50"
                                        >
                                            Become a Supplier
                                        </a>
                                    </>
                                )}
                            </div>
                        </section>

                        {/* RIGHT */}
                        <section className="relative flex items-center lg:min-h-[440px]">

                            {/* Divider */}
                            <div className="absolute bottom-8 left-0 top-8 hidden w-px bg-gradient-to-b from-transparent via-slate-200 to-transparent lg:block" />

                            <div className="max-w-[480px] lg:pl-20">

                                <div className="mb-6 font-serif text-[72px] leading-[0.7] text-orange-500/30">
                                    “
                                </div>

                                <blockquote>
                                    <p className="text-2xl font-medium leading-[1.5] tracking-[-0.025em] text-slate-700 sm:text-[28px] lg:text-[32px]">
                                        {quote}
                                    </p>
                                </blockquote>

                                <div className="mt-9 flex items-center gap-4">
                                    <span className="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">
                                        Business Insight
                                    </span>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </main>
        </>
    );
}