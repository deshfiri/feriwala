import { Form, Head, Link } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import BrandingHead from '@/components/branding-head';
import FormField from '@/components/forms/form-field';
import LanguageSwitcher from '@/components/language-switcher';
import PasswordInput from '@/components/password-input';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

import { useTranslation } from '@/hooks/use-translation';

import { home, login as clientLogin } from '@/routes';
import { register } from '@/routes/supplier';
import { store } from '@/routes/supplier/login';
import { request } from '@/routes/supplier/password';

const inputClass =
    'h-[48px] rounded-xl border-slate-200 bg-white px-4 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] placeholder:text-slate-400 focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10';

/**
 * Supplier sign in (D25). The Supplier account is a separate domain from the
 * Client/Partner ERP, so this posts to the Supplier guard's own route and
 * links across to the Client sign in for anyone who came to the wrong door.
 */
export default function SupplierLogin({ status }: { status?: string }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('supplier.auth.login_title')} />
            <BrandingHead />

            <main className="relative h-dvh overflow-hidden bg-[#fafaf9] text-slate-950">
                {/* Subtle background */}
                <div
                    className="pointer-events-none absolute inset-0"
                    aria-hidden="true"
                >
                    <div className="absolute -top-40 left-1/2 h-[460px] w-[460px] -translate-x-1/2 rounded-full bg-slate-100/80 blur-[110px]" />
                    <div className="absolute -bottom-48 left-1/2 h-[420px] w-[420px] -translate-x-1/2 rounded-full bg-[var(--brand)]/[0.035] blur-[120px]" />
                </div>

                <div className="relative z-10 flex h-full min-h-0 flex-col">
                    {/* Header */}
                    <header className="mx-auto flex h-[72px] w-full max-w-6xl shrink-0 items-center justify-between px-6 sm:px-8 lg:px-10">
                        <Link
                            href={home()}
                            className="inline-flex items-center"
                            aria-label="Home"
                        >
                            <AppLogoIcon className="h-9 w-auto max-w-[175px]" />
                        </Link>

                        <LanguageSwitcher />
                    </header>

                    {/* Centered supplier login */}
                    <section className="flex min-h-0 flex-1 items-center justify-center px-6 py-3 sm:px-8 lg:px-10">
                        <div className="w-full max-w-[420px]">
                            {/* Heading */}
                            <div className="mb-5 text-center">
                                <div className="mb-3 flex items-center justify-center gap-2.5">
                                    <span className="h-2 w-2 rounded-full bg-[var(--brand)]" />

                                    <span className="text-[10px] font-semibold tracking-[0.2em] text-slate-400 uppercase">
                                        {t('supplier.portal_name')}
                                    </span>
                                </div>

                                <h1 className="text-[30px] leading-tight font-semibold tracking-[-0.045em] text-slate-950 sm:text-[34px]">
                                    {t('supplier.auth.login_heading')}
                                </h1>

                                <p className="mx-auto mt-2 max-w-[360px] text-[13px] leading-5 text-slate-500">
                                    {t('supplier.auth.login_intro')}
                                </p>
                            </div>

                            {/* Login card */}
                            <div className="rounded-[22px] border border-slate-200/80 bg-white p-5 shadow-[0_20px_60px_-35px_rgba(15,23,42,0.20)] sm:p-6">
                                {status && (
                                    <div
                                        role="status"
                                        className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700"
                                    >
                                        {status}
                                    </div>
                                )}

                                <Form
                                    {...store.form()}
                                    resetOnSuccess={['password']}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-4">
                                            <FormField
                                                label={t(
                                                    'supplier.fields.email',
                                                )}
                                                error={errors.email}
                                                required
                                            >
                                                {(field) => (
                                                    <Input
                                                        {...field}
                                                        type="email"
                                                        name="email"
                                                        autoComplete="email"
                                                        autoFocus
                                                        required
                                                        tabIndex={1}
                                                        placeholder={t(
                                                            'supplier.auth.email_placeholder',
                                                        )}
                                                        className={inputClass}
                                                    />
                                                )}
                                            </FormField>

                                            <FormField
                                                label={t(
                                                    'supplier.fields.password',
                                                )}
                                                error={errors.password}
                                                required
                                            >
                                                {(field) => (
                                                    <PasswordInput
                                                        {...field}
                                                        name="password"
                                                        autoComplete="current-password"
                                                        required
                                                        tabIndex={2}
                                                        placeholder={t(
                                                            'supplier.auth.password_placeholder',
                                                        )}
                                                        className={inputClass}
                                                    />
                                                )}
                                            </FormField>

                                            <div className="flex items-center justify-between gap-4">
                                                <div className="flex items-center gap-2.5">
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
                                                            'supplier.fields.remember',
                                                        )}
                                                    </Label>
                                                </div>

                                                <Link
                                                    href={request()}
                                                    tabIndex={5}
                                                    className="text-xs font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                                >
                                                    {t(
                                                        'supplier.auth.forgot',
                                                    )}
                                                </Link>
                                            </div>

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                tabIndex={4}
                                                data-test="supplier-login-button"
                                                className="mt-1 h-[48px] w-full rounded-xl bg-[var(--brand)] text-sm font-semibold text-white shadow-[0_8px_20px_-10px_var(--brand)] transition-all hover:bg-[var(--brand)] hover:opacity-90"
                                            >
                                                {processing && (
                                                    <Spinner className="mr-2" />
                                                )}

                                                {processing
                                                    ? t(
                                                          'supplier.auth.signing_in',
                                                      )
                                                    : t(
                                                          'supplier.auth.login_submit',
                                                      )}
                                            </Button>
                                        </div>
                                    )}
                                </Form>

                                {/* Supplier registration */}
                                <p className="mt-4 text-center text-[13px] text-slate-500">
                                    {t('supplier.auth.no_account')}{' '}
                                    <Link
                                        href={register()}
                                        tabIndex={6}
                                        className="font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                    >
                                        {t(
                                            'supplier.auth.register_title',
                                        )}
                                    </Link>
                                </p>

                                {/* Client login */}
                                <p className="mt-4 border-t border-slate-200/80 pt-4 text-center text-xs text-slate-400">
                                    {t('supplier.auth.client_prompt')}{' '}
                                    <Link
                                        href={clientLogin()}
                                        className="font-semibold text-slate-600 transition-opacity hover:text-[var(--brand)] hover:opacity-80"
                                    >
                                        {t(
                                            'supplier.auth.login_submit',
                                        )}
                                    </Link>
                                </p>
                            </div>
                        </div>
                    </section>

                    <div className="h-3 shrink-0" />
                </div>
            </main>
        </>
    );
}

SupplierLogin.layout = null;
