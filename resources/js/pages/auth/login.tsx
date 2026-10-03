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
import { Languages } from 'lucide-react';

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

            <main className="relative h-dvh overflow-hidden bg-[#fafaf9] text-slate-950">
                <div
                    className="pointer-events-none absolute inset-0"
                    aria-hidden="true"
                >
                    <div className="absolute -top-40 left-1/2 h-[460px] w-[460px] -translate-x-1/2 rounded-full bg-slate-100/80 blur-[110px]" />
                    <div className="absolute -bottom-48 left-1/2 h-[420px] w-[420px] -translate-x-1/2 rounded-full bg-[var(--brand)]/[0.035] blur-[120px]" />
                </div>

                <div className="relative z-10 flex min-h-screen flex-col">
                    <header className="mx-auto flex h-24 w-full max-w-6xl shrink-0 items-center justify-between px-6 sm:px-8 lg:px-10">
                        <Link
                            href={home()}
                            className="inline-flex items-center"
                            aria-label="Home"
                        >
                            <AppLogoIcon className="h-8 w-auto max-w-[190px]" />
                        </Link>

                        <LanguageSwitcher />
                    </header>

                    <section className="flex flex-1 items-center justify-center px-6 py-8 sm:px-8 lg:px-10">
                        <div className="w-full max-w-[440px]">
                            <div className="mb-8 text-center">
                                <h1 className="text-[34px] leading-tight font-semibold tracking-[-0.045em] text-slate-950 sm:text-[38px]">
                                    {t('auth.login.heading')}
                                </h1>

                                <p className="mx-auto mt-3 max-w-[360px] text-[14px] leading-6 text-slate-500">
                                    {t('auth.login.intro')}
                                </p>
                            </div>

                            <div className="rounded-[24px] border border-slate-200/80 bg-white p-6 shadow-[0_20px_60px_-35px_rgba(15,23,42,0.20)] sm:p-8">
                                {staffInvitation && (
                                    <div className="mb-6">
                                        <StaffInvitationAlert
                                            invitation={staffInvitation}
                                        />
                                    </div>
                                )}

                                {status && (
                                    <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                        {status}
                                    </div>
                                )}

                                <PasskeyVerify />

                                <Form
                                    {...store.form()}
                                    resetOnSuccess={['password']}
                                    className="mt-6"
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-5">
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
                                                    {t(
                                                        'auth.login.remember',
                                                    )}
                                                </Label>
                                            </div>

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
                                                    ? t(
                                                        'auth.login.submitting',
                                                    )
                                                    : t('auth.login.submit')}
                                            </Button>
                                        </div>
                                    )}
                                </Form>

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

                                <div className="mt-7 border-t border-slate-200/80 pt-6">
                                    <div className="flex items-center justify-between gap-5">
                                        <div className="min-w-0">
                                            <p className="text-[13px] font-semibold text-slate-700">
                                                {t(
                                                    'auth.login.supplier_prompt',
                                                )}
                                            </p>

                                            <p className="mt-1 text-xs leading-5 text-slate-400">
                                                {t(
                                                    'auth.login.supplier_hint',
                                                )}
                                            </p>
                                        </div>

                                        <Link
                                            href={supplierRegister()}
                                            className="inline-flex h-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-xs font-semibold text-slate-700 transition-all hover:border-[var(--brand)] hover:text-[var(--brand)]"
                                        >
                                            {t(
                                                'auth.login.supplier_cta',
                                            )}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div className="h-10 shrink-0" />
                </div>
            </main>
        </>
    );
}

Login.layout = null;