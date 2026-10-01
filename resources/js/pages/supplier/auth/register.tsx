import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import AppLogoIcon from '@/components/app-logo-icon';
import BrandingHead from '@/components/branding-head';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import LanguageSwitcher from '@/components/language-switcher';
import PasswordInput from '@/components/password-input';
import TextArea from '@/components/forms/text-area';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { useTranslation } from '@/hooks/use-translation';

import { home } from '@/routes';
import { login } from '@/routes/supplier';
import { store } from '@/routes/supplier/register';

const stepKeys = ['business', 'contact', 'security'] as const;

export default function SupplierRegister() {
    const { t } = useTranslation();

    const [step, setStep] = useState(1);

    const steps = stepKeys.map((key, index) => ({
        number: index + 1,
        title: t(`supplier.auth.steps.${key}.title`),
        description: t(`supplier.auth.steps.${key}.description`),
    }));

    const inputClass =
        'h-[52px] rounded-xl border-slate-200 bg-white px-4 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] placeholder:text-slate-400 focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10';

    const nextStep = () => {
        setStep((current) => Math.min(current + 1, 3));
    };

    const previousStep = () => {
        setStep((current) => Math.max(current - 1, 1));
    };

    return (
        <>
            <Head title={t('supplier.auth.register_title')} />
            <BrandingHead />

            <main className="h-screen overflow-hidden bg-[#fafaf9] text-slate-950">
                <div className="grid h-full lg:grid-cols-[54%_46%]">
                    {/* =====================================================
                        LEFT
                    ===================================================== */}
                    <section className="relative h-screen overflow-y-auto bg-[#fafaf9]">
                        <div className="pointer-events-none absolute inset-0 overflow-hidden">
                            <div className="absolute -top-40 -left-32 h-[380px] w-[380px] rounded-full bg-slate-100/60 blur-[100px]" />
                        </div>

                        <div className="relative z-10 mx-auto flex min-h-full w-full max-w-[760px] flex-col px-6 pb-12 sm:px-10 lg:px-12 xl:px-16">
                            {/* Logo */}
                            <header className="flex h-24 shrink-0 items-center justify-between">
                                <Link
                                    href={home()}
                                    className="inline-flex items-center"
                                >
                                    <AppLogoIcon className="h-11 w-auto max-w-[190px]" />
                                </Link>

                                <LanguageSwitcher />
                            </header>

                            {/* Main */}
                            <div className="flex flex-1 items-center py-8">
                                <div className="w-full">
                                    {/* Heading */}
                                    <div className="mb-8">
                                        <div className="mb-4 flex items-center gap-3">
                                            <span className="h-2 w-2 rounded-full bg-[var(--brand)]" />

                                            <span className="text-[11px] font-semibold tracking-[0.22em] text-slate-400 uppercase">
                                                {t(
                                                    'supplier.auth.register_eyebrow',
                                                )}
                                            </span>
                                        </div>

                                        <h1 className="text-[34px] leading-tight font-semibold tracking-[-0.045em] text-slate-950 sm:text-[40px]">
                                            {t(
                                                'supplier.auth.register_heading',
                                            )}
                                        </h1>

                                        <p className="mt-3 max-w-[500px] text-[14px] leading-6 text-slate-500">
                                            {t('supplier.auth.register_intro')}
                                        </p>
                                    </div>

                                    {/* =====================================
                                        STEPPER
                                    ===================================== */}
                                    <div className="mb-10">
                                        <div className="relative grid grid-cols-3">
                                            {/* Background line */}
                                            <div className="absolute top-[18px] right-[16.5%] left-[16.5%] h-px bg-slate-200" />

                                            {/* Progress line */}
                                            <div
                                                className="absolute top-[18px] left-[16.5%] h-px bg-[var(--brand)] transition-all duration-300"
                                                style={{
                                                    width:
                                                        step === 1
                                                            ? '0%'
                                                            : step === 2
                                                              ? '33.5%'
                                                              : '67%',
                                                }}
                                            />

                                            {steps.map((item) => {
                                                const completed =
                                                    step > item.number;

                                                const active =
                                                    step === item.number;

                                                return (
                                                    <div
                                                        key={item.number}
                                                        className="relative z-10 flex flex-col items-center"
                                                    >
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                if (
                                                                    item.number <
                                                                    step
                                                                ) {
                                                                    setStep(
                                                                        item.number,
                                                                    );
                                                                }
                                                            }}
                                                            className={`flex h-9 w-9 items-center justify-center rounded-full border text-xs font-semibold transition-all ${
                                                                completed ||
                                                                active
                                                                    ? 'border-[var(--brand)] bg-[var(--brand)] text-white'
                                                                    : 'border-slate-200 bg-[#fafaf9] text-slate-400'
                                                            }`}
                                                        >
                                                            {completed ? (
                                                                <svg
                                                                    width="15"
                                                                    height="15"
                                                                    viewBox="0 0 24 24"
                                                                    fill="none"
                                                                    stroke="currentColor"
                                                                    strokeWidth="2.2"
                                                                    strokeLinecap="round"
                                                                    strokeLinejoin="round"
                                                                >
                                                                    <path d="M20 6 9 17l-5-5" />
                                                                </svg>
                                                            ) : (
                                                                item.number
                                                            )}
                                                        </button>

                                                        <p
                                                            className={`mt-2 text-[11px] font-semibold ${
                                                                active
                                                                    ? 'text-slate-800'
                                                                    : 'text-slate-500'
                                                            }`}
                                                        >
                                                            {item.title}
                                                        </p>

                                                        <p className="mt-0.5 hidden text-[9px] text-slate-400 sm:block">
                                                            {item.description}
                                                        </p>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    {/* =====================================
                                        FORM
                                    ===================================== */}
                                    <Form
                                        {...store.form()}
                                        resetOnSuccess={[
                                            'password',
                                            'password_confirmation',
                                        ]}
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                {/* =========================
                                                    STEP 1
                                                ========================= */}
                                                <div
                                                    className={
                                                        step === 1
                                                            ? 'block'
                                                            : 'hidden'
                                                    }
                                                >
                                                    <StepHeading
                                                        icon={
                                                            <svg
                                                                width="19"
                                                                height="19"
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                strokeWidth="1.7"
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                            >
                                                                <path d="M3 21h18" />
                                                                <path d="M5 21V7l7-4 7 4v14" />
                                                                <path d="M9 9h2" />
                                                                <path d="M13 9h2" />
                                                                <path d="M9 13h2" />
                                                                <path d="M13 13h2" />
                                                                <path d="M10 21v-4h4v4" />
                                                            </svg>
                                                        }
                                                        title={t(
                                                            'supplier.auth.sections.business.title',
                                                        )}
                                                        description={t(
                                                            'supplier.auth.sections.business.description',
                                                        )}
                                                    />

                                                    <div className="grid gap-5 sm:grid-cols-2">
                                                        <FormField
                                                            label={t(
                                                                'supplier.fields.business_name',
                                                            )}
                                                            error={
                                                                errors.business_name
                                                            }
                                                            required
                                                        >
                                                            {(field) => (
                                                                <Input
                                                                    {...field}
                                                                    name="business_name"
                                                                    autoComplete="organization"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.business_name',
                                                                    )}
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <FormField
                                                            label={t(
                                                                'supplier.fields.contact_person_name',
                                                            )}
                                                            error={
                                                                errors.contact_person_name
                                                            }
                                                            required
                                                        >
                                                            {(field) => (
                                                                <Input
                                                                    {...field}
                                                                    name="contact_person_name"
                                                                    autoComplete="name"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.contact_person_name',
                                                                    )}
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <div className="sm:col-span-2">
                                                            <FormField
                                                                label={t(
                                                                    'supplier.fields.business_address',
                                                                )}
                                                                error={
                                                                    errors.business_address
                                                                }
                                                                required
                                                            >
                                                                {(field) => (
                                                                    <TextArea
                                                                        {...field}
                                                                        name="business_address"
                                                                        placeholder={t(
                                                                            'supplier.auth.placeholders.business_address',
                                                                        )}
                                                                        required
                                                                        className="min-h-[105px] resize-none rounded-xl border-slate-200 bg-white px-4 py-3 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.02)] placeholder:text-slate-400 focus-visible:border-[var(--brand)] focus-visible:ring-[3px] focus-visible:ring-[var(--brand)]/10"
                                                                    />
                                                                )}
                                                            </FormField>
                                                        </div>
                                                    </div>

                                                    <div className="mt-8 flex justify-end">
                                                        <Button
                                                            type="button"
                                                            onClick={nextStep}
                                                            className="h-[48px] min-w-[145px] rounded-xl bg-[var(--brand)] px-6 text-sm font-semibold text-white hover:bg-[var(--brand)] hover:opacity-90"
                                                        >
                                                            {t(
                                                                'supplier.auth.continue',
                                                            )}

                                                            <svg
                                                                width="16"
                                                                height="16"
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                strokeWidth="2"
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                                className="ml-2 rtl:rotate-180"
                                                            >
                                                                <path d="m9 18 6-6-6-6" />
                                                            </svg>
                                                        </Button>
                                                    </div>
                                                </div>

                                                {/* =========================
                                                    STEP 2
                                                ========================= */}
                                                <div
                                                    className={
                                                        step === 2
                                                            ? 'block'
                                                            : 'hidden'
                                                    }
                                                >
                                                    <StepHeading
                                                        icon={
                                                            <svg
                                                                width="19"
                                                                height="19"
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
                                                        }
                                                        title={t(
                                                            'supplier.auth.sections.contact.title',
                                                        )}
                                                        description={t(
                                                            'supplier.auth.sections.contact.description',
                                                        )}
                                                    />

                                                    <div className="grid gap-5 sm:grid-cols-2">
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
                                                                    placeholder="email@example.com"
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <FormField
                                                            label={t(
                                                                'supplier.fields.mobile',
                                                            )}
                                                            error={
                                                                errors.mobile
                                                            }
                                                            required
                                                        >
                                                            {(field) => (
                                                                <Input
                                                                    {...field}
                                                                    type="tel"
                                                                    name="mobile"
                                                                    autoComplete="tel"
                                                                    placeholder="+8801XXXXXXXXX"
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <FormField
                                                            label={`${t(
                                                                'supplier.fields.trade_licence_number',
                                                            )} (${t(
                                                                'supplier.fields.optional',
                                                            )})`}
                                                            error={
                                                                errors.trade_licence_number
                                                            }
                                                        >
                                                            {(field) => (
                                                                <Input
                                                                    {...field}
                                                                    name="trade_licence_number"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.trade_licence_number',
                                                                    )}
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <FormField
                                                            label={`${t(
                                                                'supplier.fields.tax_identification_number',
                                                            )} (${t(
                                                                'supplier.fields.optional',
                                                            )})`}
                                                            error={
                                                                errors.tax_identification_number
                                                            }
                                                        >
                                                            {(field) => (
                                                                <Input
                                                                    {...field}
                                                                    name="tax_identification_number"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.tax_identification_number',
                                                                    )}
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>
                                                    </div>

                                                    <div className="mt-8 flex items-center justify-between gap-4">
                                                        <BackButton
                                                            label={t(
                                                                'supplier.auth.back',
                                                            )}
                                                            onClick={
                                                                previousStep
                                                            }
                                                        />

                                                        <Button
                                                            type="button"
                                                            onClick={nextStep}
                                                            className="h-[48px] min-w-[145px] rounded-xl bg-[var(--brand)] px-6 text-sm font-semibold text-white hover:bg-[var(--brand)] hover:opacity-90"
                                                        >
                                                            {t(
                                                                'supplier.auth.continue',
                                                            )}

                                                            <svg
                                                                width="16"
                                                                height="16"
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                strokeWidth="2"
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                                className="ml-2"
                                                            >
                                                                <path d="m9 18 6-6-6-6" />
                                                            </svg>
                                                        </Button>
                                                    </div>
                                                </div>

                                                {/* =========================
                                                    STEP 3
                                                ========================= */}
                                                <div
                                                    className={
                                                        step === 3
                                                            ? 'block'
                                                            : 'hidden'
                                                    }
                                                >
                                                    <StepHeading
                                                        icon={
                                                            <svg
                                                                width="19"
                                                                height="19"
                                                                viewBox="0 0 24 24"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                strokeWidth="1.7"
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                            >
                                                                <rect
                                                                    x="4"
                                                                    y="10"
                                                                    width="16"
                                                                    height="11"
                                                                    rx="2"
                                                                />
                                                                <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                                                                <path d="M12 14v3" />
                                                            </svg>
                                                        }
                                                        title={t(
                                                            'supplier.auth.sections.security.title',
                                                        )}
                                                        description={t(
                                                            'supplier.auth.sections.security.description',
                                                        )}
                                                    />

                                                    <div className="grid gap-5 sm:grid-cols-2">
                                                        <FormField
                                                            label={t(
                                                                'supplier.fields.password',
                                                            )}
                                                            error={
                                                                errors.password
                                                            }
                                                            required
                                                        >
                                                            {(field) => (
                                                                <PasswordInput
                                                                    {...field}
                                                                    name="password"
                                                                    autoComplete="new-password"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.password',
                                                                    )}
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>

                                                        <FormField
                                                            label={t(
                                                                'supplier.fields.password_confirmation',
                                                            )}
                                                            required
                                                        >
                                                            {(field) => (
                                                                <PasswordInput
                                                                    {...field}
                                                                    name="password_confirmation"
                                                                    autoComplete="new-password"
                                                                    placeholder={t(
                                                                        'supplier.auth.placeholders.password_confirmation',
                                                                    )}
                                                                    required
                                                                    className={
                                                                        inputClass
                                                                    }
                                                                />
                                                            )}
                                                        </FormField>
                                                    </div>

                                                    {/* Final Action */}
                                                    <div className="mt-8 flex items-center gap-4">
                                                        <BackButton
                                                            label={t(
                                                                'supplier.auth.back',
                                                            )}
                                                            onClick={
                                                                previousStep
                                                            }
                                                        />

                                                        <SubmitButton
                                                            processing={
                                                                processing
                                                            }
                                                            className="h-[48px] flex-1 rounded-xl bg-[var(--brand)] text-sm font-semibold text-white shadow-none transition-opacity hover:bg-[var(--brand)] hover:opacity-90"
                                                        >
                                                            {t(
                                                                'supplier.auth.register_submit',
                                                            )}
                                                        </SubmitButton>
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </Form>

                                    {/* Login */}
                                    <p className="mt-8 text-center text-[13px] text-slate-500">
                                        {t('supplier.auth.have_account')}{' '}
                                        <Link
                                            href={login()}
                                            className="font-semibold text-[var(--brand)] transition-opacity hover:opacity-70"
                                        >
                                            {t('supplier.auth.login_submit')}
                                        </Link>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    {/* =====================================================
                        RIGHT SIDE
                        DOES NOT SCROLL
                    ===================================================== */}
                    <section className="relative hidden h-screen overflow-hidden bg-[var(--brand)] lg:block">
                        {/* Pattern */}
                        <div
                            className="absolute inset-0 opacity-[0.08]"
                            style={{
                                backgroundImage:
                                    'radial-gradient(circle, rgba(255,255,255,0.9) 1px, transparent 1px)',
                                backgroundSize: '28px 28px',
                            }}
                        />

                        {/* Background decoration */}
                        <div className="absolute -top-[180px] -right-[180px] h-[520px] w-[520px] rounded-full border border-white/10" />

                        <div className="absolute -top-[85px] -right-[85px] h-[330px] w-[330px] rounded-full border border-white/10" />

                        <div className="absolute -bottom-[260px] -left-[180px] h-[620px] w-[620px] rounded-full bg-white/[0.055]" />

                        <div className="absolute top-[35%] left-[10%] h-[360px] w-[360px] rounded-full bg-white/[0.06] blur-[90px]" />

                        {/* Content */}
                        <div className="relative z-10 flex h-full w-full items-center px-10 xl:px-16">
                            <div className="mx-auto w-full max-w-[570px]">
                                {/* Heading */}
                                <div className="max-w-[520px]">
                                    <div className="mb-5 flex items-center gap-3">
                                        <span className="text-[10px] font-semibold tracking-[0.22em] text-white/80 uppercase">
                                            {t(
                                                'supplier.auth.showcase.eyebrow',
                                            )}
                                        </span>
                                    </div>

                                    <h2 className="text-[40px] leading-[1.08] font-semibold tracking-[-0.05em] text-white xl:text-[50px]">
                                        {t('supplier.auth.showcase.heading')}

                                        <span className="block text-white/65">
                                            {t(
                                                'supplier.auth.showcase.heading_muted',
                                            )}
                                        </span>
                                    </h2>

                                    <p className="mt-5 max-w-[450px] text-[14px] leading-7 text-white/75">
                                        {t(
                                            'supplier.auth.showcase.description',
                                        )}
                                    </p>
                                </div>

                                {/* Visual */}
                                <div
                                    className="relative mt-12 pb-10"
                                    aria-hidden="true"
                                >
                                    {/* Main Card */}
                                    <div className="relative overflow-hidden rounded-[26px] border border-white/30 bg-white p-5 shadow-[0_35px_90px_-25px_rgba(15,23,42,0.4)]">
                                        {/* Header */}
                                        <div className="mb-6 flex items-center justify-between">
                                            <div>
                                                <p className="text-[9px] font-semibold tracking-[0.15em] text-slate-400 uppercase">
                                                    {t(
                                                        'supplier.auth.showcase.workspace',
                                                    )}
                                                </p>

                                                <p className="mt-1.5 text-[15px] font-semibold text-slate-900">
                                                    {t(
                                                        'supplier.auth.showcase.overview',
                                                    )}
                                                </p>
                                            </div>

                                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--brand)]/10 text-[var(--brand)]">
                                                <svg
                                                    width="20"
                                                    height="20"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="1.7"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                >
                                                    <path d="M3 9 12 4l9 5" />
                                                    <path d="M5 10v9h14v-9" />
                                                    <path d="M9 19v-5h6v5" />
                                                </svg>
                                            </div>
                                        </div>

                                        {/* Supplier Flow */}
                                        <div className="rounded-2xl bg-slate-50 p-5">
                                            <div className="relative flex items-start justify-between">
                                                <div className="absolute top-6 right-[10%] left-[10%] h-px bg-slate-200" />

                                                <FlowItem
                                                    title={t(
                                                        'supplier.auth.showcase.products',
                                                    )}
                                                    active
                                                    icon={
                                                        <svg
                                                            width="22"
                                                            height="22"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.7"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <path d="m4 7 8-4 8 4-8 4-8-4Z" />
                                                            <path d="m4 7 8 4 8-4v10l-8 4-8-4V7Z" />
                                                        </svg>
                                                    }
                                                />

                                                <FlowItem
                                                    title={t(
                                                        'supplier.auth.showcase.inventory',
                                                    )}
                                                    icon={
                                                        <svg
                                                            width="22"
                                                            height="22"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.7"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <path d="M4 5h16v14H4z" />
                                                            <path d="M8 9h8" />
                                                            <path d="M8 13h8" />
                                                        </svg>
                                                    }
                                                />

                                                <FlowItem
                                                    title={t(
                                                        'supplier.auth.showcase.orders',
                                                    )}
                                                    icon={
                                                        <svg
                                                            width="22"
                                                            height="22"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.7"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <path d="M6 7h12l1 13H5L6 7Z" />
                                                            <path d="M9 9V6a3 3 0 0 1 6 0v3" />
                                                        </svg>
                                                    }
                                                />

                                                <FlowItem
                                                    title={t(
                                                        'supplier.auth.showcase.growth',
                                                    )}
                                                    success
                                                    icon={
                                                        <svg
                                                            width="22"
                                                            height="22"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.8"
                                                            strokeLinecap="round"
                                                            strokeLinejoin="round"
                                                        >
                                                            <path d="m5 15 5-5 4 4 5-7" />
                                                            <path d="M15 7h4v4" />
                                                        </svg>
                                                    }
                                                />
                                            </div>
                                        </div>

                                        {/* Stats */}
                                        <div className="mt-4 grid grid-cols-3 gap-3">
                                            <Stat
                                                title={t(
                                                    'supplier.auth.showcase.products',
                                                )}
                                                value="248"
                                                subtitle={t(
                                                    'supplier.auth.showcase.active_products',
                                                )}
                                            />

                                            <Stat
                                                title={t(
                                                    'supplier.auth.showcase.orders',
                                                )}
                                                value="1.2K"
                                                subtitle="+18.4%"
                                                success
                                            />

                                            <Stat
                                                title={t(
                                                    'supplier.auth.showcase.in_stock',
                                                )}
                                                value="92%"
                                                subtitle={t(
                                                    'supplier.auth.showcase.availability',
                                                )}
                                            />
                                        </div>
                                    </div>

                                    {/* Floating verified */}
                                    <div className="absolute -top-5 -left-5 flex items-center gap-3 rounded-2xl border border-white/70 bg-white px-4 py-3 shadow-[0_18px_45px_-15px_rgba(15,23,42,0.3)]">
                                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                                            <svg
                                                width="18"
                                                height="18"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <path d="M20 6 9 17l-5-5" />
                                            </svg>
                                        </div>

                                        <div>
                                            <p className="text-[9px] text-slate-400">
                                                {t(
                                                    'supplier.auth.showcase.supplier',
                                                )}
                                            </p>

                                            <p className="text-[11px] font-semibold text-slate-800">
                                                {t(
                                                    'supplier.auth.showcase.account_ready',
                                                )}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Package */}
                                    <div className="absolute -right-5 -bottom-2 flex h-[70px] w-[70px] rotate-6 items-center justify-center rounded-[22px] border border-white/30 bg-white/15 text-white shadow-lg backdrop-blur-sm">
                                        <svg
                                            width="32"
                                            height="32"
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
                                        </svg>
                                    </div>
                                </div>

                                {/* Trust */}
                                <div className="mt-5 flex items-center gap-3 text-white/70">
                                    <svg
                                        width="17"
                                        height="17"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
                                        <path d="m9 12 2 2 4-4" />
                                    </svg>

                                    <span className="text-xs font-medium">
                                        {t('supplier.auth.showcase.trust')}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}

/* =========================================================
   SMALL UI COMPONENTS
========================================================= */

function StepHeading({
    icon,
    title,
    description,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <div className="mb-6 flex items-center gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand)]/10 text-[var(--brand)]">
                {icon}
            </div>

            <div>
                <h2 className="text-[15px] font-semibold text-slate-800">
                    {title}
                </h2>

                <p className="mt-0.5 text-xs text-slate-400">{description}</p>
            </div>
        </div>
    );
}

function BackButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            onClick={onClick}
            className="h-[48px] rounded-xl border-slate-200 bg-white px-5 text-sm font-semibold text-slate-600 hover:bg-slate-50"
        >
            <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="mr-2 rtl:rotate-180"
            >
                <path d="m15 18-6-6 6-6" />
            </svg>

            {label}
        </Button>
    );
}

function FlowItem({
    title,
    icon,
    active = false,
    success = false,
}: {
    title: string;
    icon: React.ReactNode;
    active?: boolean;
    success?: boolean;
}) {
    return (
        <div className="relative z-10 w-[70px] text-center">
            <div
                className={`mx-auto flex h-12 w-12 items-center justify-center rounded-xl border bg-white shadow-sm ${
                    success
                        ? 'border-emerald-100 text-emerald-500'
                        : active
                          ? 'border-slate-100 text-[var(--brand)]'
                          : 'border-slate-100 text-slate-500'
                }`}
            >
                {icon}
            </div>

            <p className="mt-2 text-[9px] font-semibold text-slate-700">
                {title}
            </p>
        </div>
    );
}

function Stat({
    title,
    value,
    subtitle,
    success = false,
}: {
    title: string;
    value: string;
    subtitle: string;
    success?: boolean;
}) {
    return (
        <div className="rounded-xl border border-slate-100 p-3">
            <p className="text-[8px] font-medium tracking-wider text-slate-400 uppercase">
                {title}
            </p>

            <p className="mt-1.5 text-lg font-bold text-slate-900">{value}</p>

            <p
                className={`mt-1 text-[8px] ${
                    success ? 'font-medium text-emerald-500' : 'text-slate-400'
                }`}
            >
                {subtitle}
            </p>
        </div>
    );
}

SupplierRegister.layout = null;
