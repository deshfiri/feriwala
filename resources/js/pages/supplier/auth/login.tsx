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
    'h-[52px] rounded-xl border-slate-200 bg-white px-4 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] placeholder:text-slate-400 focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10';

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

            <main className="min-h-screen overflow-hidden bg-[#fafaf9] text-slate-950">
                <div className="grid min-h-screen lg:grid-cols-[44%_56%]">
                    <section className="relative flex min-h-screen flex-col bg-[#fafaf9] px-6 sm:px-10 lg:px-12 xl:px-20">
                        <div className="pointer-events-none absolute inset-0 overflow-hidden">
                            <div className="absolute -top-40 -left-32 h-[380px] w-[380px] rounded-full bg-slate-100/60 blur-[100px]" />
                        </div>

                        <header className="relative z-10 flex h-24 shrink-0 items-center justify-between">
                            <Link
                                href={home()}
                                className="inline-flex items-center"
                            >
                                <AppLogoIcon className="h-11 w-auto max-w-[190px]" />
                            </Link>

                            <LanguageSwitcher />
                        </header>

                        <div className="relative z-10 flex flex-1 items-center py-8 lg:py-10">
                            <div className="w-full max-w-[430px]">
                                <div className="mb-8">
                                    <div className="mb-4 flex items-center gap-3">
                                        <span className="h-2 w-2 rounded-full bg-[var(--brand)]" />

                                        <span className="text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                            {t('supplier.portal_name')}
                                        </span>
                                    </div>

                                    <h1 className="text-[34px] leading-tight font-semibold tracking-[-0.045em] text-slate-950 sm:text-[38px]">
                                        {t('supplier.auth.login_heading')}
                                    </h1>

                                    <p className="mt-3 max-w-[360px] text-[14px] leading-6 text-slate-500">
                                        {t('supplier.auth.login_intro')}
                                    </p>
                                </div>

                                {status && (
                                    <div
                                        role="status"
                                        className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
                                    >
                                        {status}
                                    </div>
                                )}

                                <Form
                                    {...store.form()}
                                    resetOnSuccess={['password']}
                                >
                                    {({ processing, errors }) => (
                                        <div className="space-y-5">
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
                                                    {t('supplier.auth.forgot')}
                                                </Link>
                                            </div>

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                tabIndex={4}
                                                data-test="supplier-login-button"
                                                className="mt-2 h-[52px] w-full rounded-xl bg-[var(--brand)] text-sm font-semibold text-white shadow-[0_8px_20px_-10px_var(--brand)] transition-all hover:bg-[var(--brand)] hover:opacity-90"
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

                                <p className="mt-6 text-center text-[13px] text-slate-500">
                                    {t('supplier.auth.no_account')}{' '}
                                    <Link
                                        href={register()}
                                        tabIndex={6}
                                        className="font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                    >
                                        {t('supplier.auth.register_title')}
                                    </Link>
                                </p>

                                <p className="mt-8 border-t border-slate-200/80 pt-6 text-center text-xs text-slate-400">
                                    {t('supplier.auth.client_prompt')}{' '}
                                    <Link
                                        href={clientLogin()}
                                        className="font-semibold text-slate-600 transition-opacity hover:opacity-70"
                                    >
                                        {t('supplier.auth.login_submit')}
                                    </Link>
                                </p>
                            </div>
                        </div>

                        <div className="relative z-10 h-8 shrink-0" />
                    </section>

                    <section
                        aria-hidden="true"
                        className="relative hidden min-h-screen overflow-hidden bg-[var(--brand)] lg:block"
                    >
                        <div
                            className="absolute inset-0 opacity-[0.08]"
                            style={{
                                backgroundImage:
                                    'radial-gradient(circle, rgba(255,255,255,0.9) 1px, transparent 1px)',
                                backgroundSize: '28px 28px',
                            }}
                        />

                        <div className="absolute -top-[180px] -right-[190px] h-[520px] w-[520px] rounded-full border border-white/10" />
                        <div className="absolute -top-[90px] -right-[95px] h-[340px] w-[340px] rounded-full border border-white/10" />
                        <div className="absolute -bottom-[300px] -left-[200px] h-[650px] w-[650px] rounded-full bg-white/[0.055]" />

                        <div className="relative z-10 flex min-h-screen items-center px-12 py-14 xl:px-20">
                            <div className="mx-auto w-full max-w-[600px]">
                                <span className="text-[11px] font-semibold tracking-[0.22em] text-white/80 uppercase">
                                    {t('supplier.auth.showcase.eyebrow')}
                                </span>

                                <h2 className="mt-5 text-[44px] leading-[1.08] font-semibold tracking-[-0.05em] text-white xl:text-[56px]">
                                    {t('supplier.auth.showcase.heading')}
                                    <span className="block text-white/70">
                                        {t(
                                            'supplier.auth.showcase.heading_muted',
                                        )}
                                    </span>
                                </h2>

                                <p className="mt-5 max-w-[470px] text-[15px] leading-7 text-white/75">
                                    {t('supplier.auth.showcase.description')}
                                </p>

                                <div className="mt-10 grid max-w-[520px] grid-cols-3 gap-3">
                                    {(
                                        [
                                            'products',
                                            'inventory',
                                            'orders',
                                        ] as const
                                    ).map((key) => (
                                        <div
                                            key={key}
                                            className="rounded-2xl border border-white/20 bg-white/10 px-4 py-5 text-sm font-semibold text-white backdrop-blur-sm"
                                        >
                                            {t(`supplier.auth.showcase.${key}`)}
                                        </div>
                                    ))}
                                </div>

                                <p className="mt-7 text-xs font-medium text-white/70">
                                    {t('supplier.auth.showcase.trust')}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}

SupplierLogin.layout = null;
