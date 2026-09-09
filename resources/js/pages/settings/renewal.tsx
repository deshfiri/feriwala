import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import SubmitButton from '@/components/forms/submit-button';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { pay } from '@/routes/subscription/renew';
import { show as subscriptionShow } from '@/routes/subscription';
import type { Money } from '@/lib/money';
import type { AccountSubscriptionRow } from '@/types';

type QuoteLine = {
    type: string;
    label: string;
    amount: Money;
    is_deduction: boolean;
};

type Props = {
    current: AccountSubscriptionRow | null;
    renewal: {
        id: string;
        package: string;
        validity_days: number | null;
        grace_period_days: number | null;
        renewal_frequency: string | null;
        starts_at: string;
    };
    quote: {
        lines: QuoteLine[];
        subtotal: Money;
        total: Money;
        currency: string;
        is_payable: boolean;
    };
    gateways: { name: string; label: string }[];
};

/**
 * Renewing the current term (§8.2, §8.4).
 *
 * The itemised breakdown is the point. A renewal is a fresh agreement to what
 * the package says today, so the price and the term length are stated in full
 * before anything is paid — quietly charging last year's figure, or this year's
 * without saying so, would both be worse than asking.
 *
 * No total is computed here. The figures arrive calculated and this only
 * renders them (§36.1).
 */
export default function Renewal({ current, renewal, quote, gateways }: Props) {
    const { t, locale } = useTranslation();
    const [gateway, setGateway] = useState(gateways[0]?.name ?? '');

    const startsAt = new Date(renewal.starts_at).toLocaleDateString(locale);

    return (
        <>
            <Head title={t('package.renewal.title')} />

            <h1 className="sr-only">{t('package.renewal.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('package.renewal.title')}
                    description={t('package.renewal.description')}
                />

                <section className="bg-card space-y-3 rounded-xl border p-4">
                    <h2 className="text-sm font-medium">
                        {t('package.renewal.summary')}
                    </h2>

                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {current?.package ?? renewal.package}
                            </dt>
                            <dd className="font-medium">
                                {renewal.validity_days === null
                                    ? t('package.subscription.no_expiry')
                                    : t('package.renewal.runs_for', {
                                          days: renewal.validity_days,
                                      })}
                            </dd>
                        </div>

                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('package.renewal.starts')}
                            </dt>
                            <dd className="font-medium">{startsAt}</dd>
                        </div>
                    </dl>

                    <p className="text-muted-foreground text-xs">
                        {t('package.renewal.starts_help')}
                    </p>
                </section>

                <section className="bg-card border-border overflow-hidden rounded-xl border">
                    <dl className="divide-border divide-y">
                        {quote.lines.map((line) => (
                            <div
                                key={line.type}
                                className="flex items-center justify-between gap-4 px-4 py-2.5"
                            >
                                <dt className="text-sm">{line.label}</dt>
                                <dd>
                                    <MoneyAmount
                                        amount={line.amount}
                                        direction={
                                            line.is_deduction
                                                ? 'credit'
                                                : 'neutral'
                                        }
                                        showSign={line.is_deduction}
                                    />
                                </dd>
                            </div>
                        ))}

                        <div className="bg-muted flex items-center justify-between gap-4 px-4 py-3">
                            <dt className="text-sm font-semibold">
                                {t('package.renewal.total')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={quote.total}
                                    size="large"
                                />
                            </dd>
                        </div>
                    </dl>
                </section>

                {quote.is_payable ? (
                    <Form
                        {...pay.form()}
                        className="bg-card border-border space-y-4 rounded-xl border p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <fieldset className="space-y-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        {t('package.renewal.pay')}
                                    </legend>

                                    {gateways.map((option) => (
                                        <label
                                            key={option.name}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm',
                                                gateway === option.name
                                                    ? 'border-brand bg-brand-subtle'
                                                    : 'border-input hover:bg-muted',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="gateway"
                                                value={option.name}
                                                checked={
                                                    gateway === option.name
                                                }
                                                onChange={() =>
                                                    setGateway(option.name)
                                                }
                                                className="accent-brand"
                                            />
                                            <span className="font-medium">
                                                {option.label}
                                            </span>
                                        </label>
                                    ))}
                                </fieldset>

                                {errors.gateway && (
                                    <p className="text-danger text-sm font-medium">
                                        {errors.gateway}
                                    </p>
                                )}

                                <SubmitButton
                                    processing={processing}
                                    disabled={gateways.length === 0}
                                    className="w-full"
                                >
                                    {t('package.renewal.pay')}
                                </SubmitButton>
                            </>
                        )}
                    </Form>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        {t('package.renewal.free')}
                    </p>
                )}
            </div>
        </>
    );
}

Renewal.layout = {
    breadcrumbs: [
        { title: 'Package', href: subscriptionShow() },
        { title: 'Renew', href: pay() },
    ],
};
