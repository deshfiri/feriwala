import { Form, Head, Link } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import BrandingHead from '@/components/branding-head';
import InputError from '@/components/input-error';
import LanguageSwitcher from '@/components/language-switcher';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import StaffInvitationAlert from '@/components/staff-invitation-alert';
import TextLink from '@/components/text-link';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

import { useTranslation } from '@/hooks/use-translation';

import { home, register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { register as supplierRegister } from '@/routes/supplier';

import type { StaffInvitationContext } from '@/components/staff-invitation-alert';

type Props = {
    status?: string;
    canResetPassword: boolean;
    staffInvitation?: StaffInvitationContext | null;
};

export default function Login({
    status,
    canResetPassword,
    staffInvitation,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('auth.login.title')} />
            <BrandingHead />

            <main className="min-h-screen overflow-hidden bg-[#fafaf9] text-slate-950">
                <div className="grid min-h-screen lg:grid-cols-[44%_56%]">
                    {/* =====================================================
                        LEFT SIDE
                    ===================================================== */}
                    <section className="relative flex min-h-screen flex-col bg-[#fafaf9] px-6 sm:px-10 lg:px-12 xl:px-20">
                        {/* very subtle background */}
                        <div className="pointer-events-none absolute inset-0 overflow-hidden">
                            <div className="absolute -top-40 -left-32 h-[380px] w-[380px] rounded-full bg-slate-100/60 blur-[100px]" />
                        </div>

                        {/* Logo */}
                        <header className="relative z-10 flex h-24 shrink-0 items-center justify-between">
                            <Link
                                href={home()}
                                className="inline-flex items-center"
                            >
                                <AppLogoIcon className="h-11 w-auto max-w-[190px]" />
                            </Link>

                            <LanguageSwitcher />
                        </header>

                        {/* Login Area */}
                        <div className="relative z-10 flex flex-1 items-center py-8 lg:py-10">
                            <div className="w-full max-w-[430px]">
                                {/* Heading */}
                                <div className="mb-8">
                                    <h1 className="text-[34px] leading-tight font-semibold tracking-[-0.045em] text-slate-950 sm:text-[38px]">
                                        {t('auth.login.heading')}
                                    </h1>

                                    <p className="mt-3 max-w-[360px] text-[14px] leading-6 text-slate-500">
                                        {t('auth.login.intro')}
                                    </p>
                                </div>

                                {/* Invitation */}
                                {staffInvitation && (
                                    <div className="mb-6">
                                        <StaffInvitationAlert
                                            invitation={staffInvitation}
                                        />
                                    </div>
                                )}

                                {/* Status */}
                                {status && (
                                    <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                        {status}
                                    </div>
                                )}

                                {/* Passkey */}
                                <PasskeyVerify />

                                {/* Form */}
                                <Form
                                    {...store.form()}
                                    resetOnSuccess={['password']}
                                    className="mt-6"
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-5">
                                            {/* Email */}
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor="email"
                                                    className="text-[13px] font-medium text-slate-700"
                                                >
                                                    {t('auth.login.email')}
                                                </Label>

                                                <div className="relative">
                                                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                                        <svg
                                                            width="18"
                                                            height="18"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.7"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <rect
                                                                x="3"
                                                                y="5"
                                                                width="18"
                                                                height="14"
                                                                rx="2"
                                                            />
                                                            <path d="m3 7 9 6 9-6" />
                                                        </svg>
                                                    </div>

                                                    <Input
                                                        id="email"
                                                        type="email"
                                                        name="email"
                                                        required
                                                        autoFocus
                                                        tabIndex={1}
                                                        autoComplete="email"
                                                        placeholder={t(
                                                            'auth.login.email_placeholder',
                                                        )}
                                                        className="h-[52px] rounded-xl border-slate-200 bg-white pr-4 pl-12 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] placeholder:text-slate-400 focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10"
                                                    />
                                                </div>

                                                <InputError
                                                    message={errors.email}
                                                />
                                            </div>

                                            {/* Password */}
                                            <div className="space-y-2">
                                                <div className="flex items-center justify-between gap-4">
                                                    <Label
                                                        htmlFor="password"
                                                        className="text-[13px] font-medium text-slate-700"
                                                    >
                                                        {t(
                                                            'auth.login.password',
                                                        )}
                                                    </Label>

                                                    {canResetPassword && (
                                                        <TextLink
                                                            href={request()}
                                                            tabIndex={5}
                                                            className="text-xs font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                                        >
                                                            {t(
                                                                'auth.login.forgot',
                                                            )}
                                                        </TextLink>
                                                    )}
                                                </div>

                                                <PasswordInput
                                                    id="password"
                                                    name="password"
                                                    required
                                                    tabIndex={2}
                                                    autoComplete="current-password"
                                                    placeholder={t(
                                                        'auth.login.password_placeholder',
                                                    )}
                                                    className="h-[52px] rounded-xl border-slate-200 bg-white px-4 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10"
                                                />

                                                <InputError
                                                    message={errors.password}
                                                />
                                            </div>

                                            {/* Remember */}
                                            <div className="flex items-center gap-2.5 pt-1">
                                                <Checkbox
                                                    id="remember"
                                                    name="remember"
                                                    tabIndex={3}
                                                    className="h-[17px] w-[17px] rounded border-slate-300 data-[state=checked]:border-[var(--brand)] data-[state=checked]:bg-[var(--brand)]"
                                                />

                                                <Label
                                                    htmlFor="remember"
                                                    className="cursor-pointer text-[13px] font-normal text-slate-500"
                                                >
                                                    {t('auth.login.remember')}
                                                </Label>
                                            </div>

                                            {/* Login */}
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                tabIndex={4}
                                                data-test="login-button"
                                                className="mt-2 h-[52px] w-full rounded-xl bg-[var(--brand)] text-sm font-semibold text-white shadow-[0_8px_20px_-10px_var(--brand)] transition-all hover:bg-[var(--brand)] hover:opacity-90"
                                            >
                                                {processing && (
                                                    <Spinner className="mr-2" />
                                                )}

                                                {processing
                                                    ? t('auth.login.submitting')
                                                    : t('auth.login.submit')}
                                            </Button>
                                        </div>
                                    )}
                                </Form>

                                {/* Client Registration */}
                                <p className="mt-6 text-center text-[13px] text-slate-500">
                                    {t('auth.login.new_here')}{' '}
                                    <TextLink
                                        href={register({
                                            query: {
                                                invitation:
                                                    staffInvitation?.token,
                                            },
                                        })}
                                        data-test="register-link"
                                        tabIndex={6}
                                        className="font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                    >
                                        {t('auth.login.create_account')}
                                    </TextLink>
                                </p>

                                {/* Supplier */}
                                <div className="mt-8 border-t border-slate-200/80 pt-6">
                                    <div className="flex items-center justify-between gap-5">
                                        <div>
                                            <p className="text-[13px] font-semibold text-slate-700">
                                                {t(
                                                    'auth.login.supplier_prompt',
                                                )}
                                            </p>

                                            <p className="mt-1 text-xs leading-5 text-slate-400">
                                                {t('auth.login.supplier_hint')}
                                            </p>
                                        </div>

                                        <Link
                                            href={supplierRegister()}
                                            className="inline-flex h-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-xs font-semibold text-slate-700 transition-all hover:border-[var(--brand)] hover:text-[var(--brand)]"
                                        >
                                            {t('auth.login.supplier_cta')}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="relative z-10 h-8 shrink-0" />
                    </section>

                    {/* =====================================================
                        RIGHT SIDE
                    ===================================================== */}
                    <section className="relative hidden min-h-screen overflow-hidden bg-[var(--brand)] lg:block">
                        {/* Background depth */}
                        <div
                            className="absolute inset-0 opacity-[0.08]"
                            style={{
                                backgroundImage:
                                    'radial-gradient(circle, rgba(255,255,255,0.9) 1px, transparent 1px)',
                                backgroundSize: '28px 28px',
                            }}
                        />

                        {/* large background circles */}
                        <div className="absolute -top-[180px] -right-[190px] h-[520px] w-[520px] rounded-full border border-white/10" />

                        <div className="absolute -top-[90px] -right-[95px] h-[340px] w-[340px] rounded-full border border-white/10" />

                        <div className="absolute -bottom-[300px] -left-[200px] h-[650px] w-[650px] rounded-full bg-white/[0.055]" />

                        {/* Main */}
                        <div className="relative z-10 flex min-h-screen items-center px-12 py-14 xl:px-20">
                            <div className="mx-auto w-full max-w-[700px]">
                                {/* Right heading */}
                                <div className="mb-10 max-w-[570px]">
                                    <div className="mb-5 flex items-center gap-3">
                                        <span className="text-[11px] font-semibold tracking-[0.22em] text-white/80 uppercase">
                                            {t('auth.showcase.eyebrow')}
                                        </span>
                                    </div>

                                    <h2 className="text-[44px] leading-[1.08] font-semibold tracking-[-0.05em] text-white xl:text-[56px]">
                                        {t('auth.showcase.heading')}
                                        <span className="block text-white/70">
                                            {t('auth.showcase.heading_muted')}
                                        </span>
                                    </h2>

                                    <p className="mt-5 max-w-[470px] text-[15px] leading-7 text-white/75">
                                        {t('auth.showcase.description')}
                                    </p>
                                </div>

                                {/* =================================================
                                    VISUAL COMPOSITION
                                ================================================= */}
                                <div
                                    className="relative pt-4 pr-5 pb-10"
                                    aria-hidden="true"
                                >
                                    {/* Main dashboard */}
                                    <div className="relative overflow-hidden rounded-[26px] border border-white/30 bg-white shadow-[0_35px_90px_-25px_rgba(15,23,42,0.38)]">
                                        {/* Browser Top */}
                                        <div className="flex h-12 items-center justify-between border-b border-slate-100 px-5">
                                            <div className="flex items-center gap-1.5">
                                                <span className="h-2.5 w-2.5 rounded-full bg-red-300" />
                                                <span className="h-2.5 w-2.5 rounded-full bg-amber-300" />
                                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-300" />
                                            </div>

                                            <div className="h-2.5 w-28 rounded-full bg-slate-100" />

                                            <div className="h-7 w-7 rounded-full bg-slate-100" />
                                        </div>

                                        <div className="grid min-h-[300px] grid-cols-[120px_1fr]">
                                            {/* Sidebar */}
                                            <aside className="border-r border-slate-100 bg-slate-50/80 p-4">
                                                <div className="mb-7 flex h-9 w-9 items-center justify-center rounded-xl bg-[var(--brand)] text-white">
                                                    <svg
                                                        width="18"
                                                        height="18"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        strokeWidth="1.8"
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                    >
                                                        <rect
                                                            x="3"
                                                            y="3"
                                                            width="7"
                                                            height="7"
                                                            rx="1.5"
                                                        />
                                                        <rect
                                                            x="14"
                                                            y="3"
                                                            width="7"
                                                            height="7"
                                                            rx="1.5"
                                                        />
                                                        <rect
                                                            x="3"
                                                            y="14"
                                                            width="7"
                                                            height="7"
                                                            rx="1.5"
                                                        />
                                                        <rect
                                                            x="14"
                                                            y="14"
                                                            width="7"
                                                            height="7"
                                                            rx="1.5"
                                                        />
                                                    </svg>
                                                </div>

                                                <div className="space-y-3">
                                                    <div className="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 shadow-sm">
                                                        <span className="h-2 w-2 rounded-full bg-[var(--brand)]" />
                                                        <span className="h-2 w-12 rounded bg-slate-300" />
                                                    </div>

                                                    <div className="flex items-center gap-2 px-2.5 py-2">
                                                        <span className="h-2 w-2 rounded-full bg-slate-200" />
                                                        <span className="h-2 w-10 rounded bg-slate-200" />
                                                    </div>

                                                    <div className="flex items-center gap-2 px-2.5 py-2">
                                                        <span className="h-2 w-2 rounded-full bg-slate-200" />
                                                        <span className="h-2 w-14 rounded bg-slate-200" />
                                                    </div>

                                                    <div className="flex items-center gap-2 px-2.5 py-2">
                                                        <span className="h-2 w-2 rounded-full bg-slate-200" />
                                                        <span className="h-2 w-9 rounded bg-slate-200" />
                                                    </div>
                                                </div>
                                            </aside>

                                            {/* Dashboard body */}
                                            <div className="p-5">
                                                {/* Dashboard heading */}
                                                <div className="mb-5 flex items-center justify-between">
                                                    <div>
                                                        <p className="text-[10px] font-medium tracking-[0.12em] text-slate-400 uppercase">
                                                            {t(
                                                                'auth.showcase.overview',
                                                            )}
                                                        </p>

                                                        <p className="mt-1 text-sm font-semibold text-slate-800">
                                                            {t(
                                                                'auth.showcase.dashboard',
                                                            )}
                                                        </p>
                                                    </div>

                                                    <div className="rounded-lg border border-slate-100 px-3 py-1.5 text-[9px] font-medium text-slate-400">
                                                        {t(
                                                            'auth.showcase.this_month',
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Stats */}
                                                <div className="grid grid-cols-3 gap-3">
                                                    <div className="rounded-xl border border-slate-100 bg-white p-3">
                                                        <div className="mb-3 flex items-center justify-between">
                                                            <span className="text-[9px] font-medium text-slate-400">
                                                                {t(
                                                                    'auth.showcase.orders',
                                                                )}
                                                            </span>

                                                            <span className="flex h-6 w-6 items-center justify-center rounded-md bg-[var(--brand)]/10 text-[var(--brand)]">
                                                                <svg
                                                                    width="12"
                                                                    height="12"
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    strokeWidth="2"
                                                                >
                                                                    <path d="M6 7h12l1 13H5L6 7Z" />
                                                                    <path d="M9 9V6a3 3 0 0 1 6 0v3" />
                                                                </svg>
                                                            </span>
                                                        </div>

                                                        <p className="text-lg font-bold tracking-tight text-slate-900">
                                                            1,284
                                                        </p>

                                                        <p className="mt-1 text-[8px] font-medium text-emerald-500">
                                                            {t(
                                                                'auth.showcase.orders_change',
                                                            )}
                                                        </p>
                                                    </div>

                                                    <div className="rounded-xl border border-slate-100 bg-white p-3">
                                                        <div className="mb-3 flex items-center justify-between">
                                                            <span className="text-[9px] font-medium text-slate-400">
                                                                {t(
                                                                    'auth.showcase.revenue',
                                                                )}
                                                            </span>

                                                            <span className="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-500">
                                                                <svg
                                                                    width="12"
                                                                    height="12"
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    strokeWidth="2"
                                                                >
                                                                    <path d="M12 2v20" />
                                                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
                                                                </svg>
                                                            </span>
                                                        </div>

                                                        <p className="text-lg font-bold tracking-tight text-slate-900">
                                                            2.4M
                                                        </p>

                                                        <p className="mt-1 text-[8px] text-slate-400">
                                                            {t(
                                                                'auth.showcase.total_revenue',
                                                            )}
                                                        </p>
                                                    </div>

                                                    <div className="rounded-xl border border-slate-100 bg-white p-3">
                                                        <div className="mb-3 flex items-center justify-between">
                                                            <span className="text-[9px] font-medium text-slate-400">
                                                                {t(
                                                                    'auth.showcase.customers',
                                                                )}
                                                            </span>

                                                            <span className="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-500">
                                                                <svg
                                                                    width="12"
                                                                    height="12"
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    strokeWidth="2"
                                                                >
                                                                    <circle
                                                                        cx="9"
                                                                        cy="7"
                                                                        r="4"
                                                                    />
                                                                    <path d="M2 21v-2a7 7 0 0 1 14 0v2" />
                                                                    <path d="M16 3.1a4 4 0 0 1 0 7.8" />
                                                                    <path d="M22 21v-2a7 7 0 0 0-5-6.7" />
                                                                </svg>
                                                            </span>
                                                        </div>

                                                        <p className="text-lg font-bold tracking-tight text-slate-900">
                                                            8,420
                                                        </p>

                                                        <p className="mt-1 text-[8px] text-slate-400">
                                                            {t(
                                                                'auth.showcase.active_customers',
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>

                                                {/* Chart */}
                                                <div className="mt-4 rounded-xl border border-slate-100 p-4">
                                                    <div className="mb-5 flex items-center justify-between">
                                                        <div>
                                                            <p className="text-[10px] font-semibold text-slate-700">
                                                                {t(
                                                                    'auth.showcase.sales_overview',
                                                                )}
                                                            </p>

                                                            <p className="mt-0.5 text-[8px] text-slate-400">
                                                                {t(
                                                                    'auth.showcase.performance',
                                                                )}
                                                            </p>
                                                        </div>

                                                        <span className="text-[9px] font-medium text-[var(--brand)]">
                                                            +24.8%
                                                        </span>
                                                    </div>

                                                    <div className="flex h-[75px] items-end gap-2">
                                                        {[
                                                            34, 46, 41, 58, 51,
                                                            67, 61, 76, 70, 88,
                                                            80, 94,
                                                        ].map(
                                                            (height, index) => (
                                                                <div
                                                                    key={index}
                                                                    className="group flex h-full flex-1 items-end"
                                                                >
                                                                    <div
                                                                        className="w-full rounded-t-sm bg-[var(--brand)] opacity-20 transition-opacity group-hover:opacity-80"
                                                                        style={{
                                                                            height: `${height}%`,
                                                                        }}
                                                                    />
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Package vector */}
                                    <div className="absolute -right-1 -bottom-5 flex h-[72px] w-[72px] rotate-6 items-center justify-center rounded-[22px] border border-white/40 bg-white/15 text-white shadow-lg backdrop-blur-sm">
                                        <svg
                                            width="34"
                                            height="34"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="1.5"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            <path d="m4 7 8-4 8 4-8 4-8-4Z" />
                                            <path d="m4 7 8 4 8-4v10l-8 4-8-4V7Z" />
                                            <path d="M12 11v10" />
                                            <path d="m8 5 8 4" />
                                        </svg>
                                    </div>
                                </div>

                                {/* Bottom trust line */}
                                <div className="mt-7 flex items-center gap-4">
                                    <div className="flex -space-x-2">
                                        <span className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-[var(--brand)] bg-white text-[8px] font-bold text-slate-700">
                                            A
                                        </span>

                                        <span className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-[var(--brand)] bg-slate-800 text-[8px] font-bold text-white">
                                            B
                                        </span>

                                        <span className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-[var(--brand)] bg-white text-[8px] font-bold text-slate-700">
                                            C
                                        </span>
                                    </div>

                                    <p className="text-xs font-medium text-white/70">
                                        {t('auth.showcase.trust')}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}

Login.layout = null;
