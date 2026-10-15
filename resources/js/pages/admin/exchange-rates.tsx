import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import PaymentGatewayController from '@/actions/App/Http/Controllers/Admin/PaymentGatewayController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import Notice from '@/components/notice';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { settings } from '@/routes/admin';
import { exchangeRates, switches } from '@/routes/admin/gateways';

type RateRow = {
    currency: string;
    rate: string | null;
    /** What the sample amount comes to at the saved rate, worked out by the server. */
    sample: Money | null;
};

type DependentGateway = {
    name: string;
    label: string;
    currencies: string[];
    is_available: boolean;
    offered_for_taka: boolean;
    charge_currency: string | null;
};

type Props = {
    rates: RateRow[];
    gateways: DependentGateway[];
    sample: Money;
    can: { manage: boolean };
};

/**
 * The rates that let a Taka payment go through an international gateway
 * (D4, amended).
 *
 * The rate is typed as text and sent as typed; the server validates it and does
 * every conversion. The "comes to" figures are the server's, computed from the
 * saved rates — this page never multiplies or divides anything.
 */
export default function ExchangeRates({ rates, gateways, sample, can }: Props) {
    const { t } = useTranslation();
    const [values, setValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(rates.map((row) => [row.currency, row.rate ?? ''])),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const save = () => {
        router.put(
            PaymentGatewayController.updateExchangeRates.url(),
            { rates: values },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const missingFor = (gateway: DependentGateway): string =>
        gateway.currencies.join(', ');

    return (
        <>
            <Head title={t('gateways.rates.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('gateways.rates.title')}
                    description={t('gateways.rates.description')}
                    back={{
                        href: switches(),
                        label: t('gateways.rates.back'),
                    }}
                />

                {!can.manage && (
                    <Notice tone="info">{t('gateways.rates.read_only')}</Notice>
                )}

                <SectionCard
                    title={t('gateways.rates.rates_title')}
                    description={t('gateways.rates.rates_description')}
                >
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        {rates.map((row) => {
                            const id = `rate-${row.currency}`;

                            return (
                                <div
                                    key={row.currency}
                                    className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end"
                                    data-test={`rate-row-${row.currency}`}
                                >
                                    <div className="grid gap-2">
                                        <Label htmlFor={id}>
                                            {t('gateways.rates.per_unit', {
                                                currency: row.currency,
                                            })}
                                        </Label>
                                        <div className="flex items-center gap-2">
                                            <span
                                                aria-hidden="true"
                                                className="text-muted-foreground"
                                            >
                                                ৳
                                            </span>
                                            <Input
                                                id={id}
                                                name={`rates.${row.currency}`}
                                                inputMode="decimal"
                                                autoComplete="off"
                                                value={
                                                    values[row.currency] ?? ''
                                                }
                                                disabled={!can.manage}
                                                onChange={(event) =>
                                                    setValues((current) => ({
                                                        ...current,
                                                        [row.currency]:
                                                            event.target.value,
                                                    }))
                                                }
                                                aria-invalid={
                                                    errors[
                                                        `rates.${row.currency}`
                                                    ]
                                                        ? true
                                                        : undefined
                                                }
                                                className="tabular max-w-48"
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                errors[`rates.${row.currency}`]
                                            }
                                        />
                                    </div>

                                    <p
                                        className="text-muted-foreground text-sm tabular-nums"
                                        aria-live="polite"
                                    >
                                        {row.sample ? (
                                            <>
                                                <MoneyAmount amount={sample} />{' '}
                                                {t('gateways.rates.sample')}{' '}
                                                <MoneyAmount
                                                    amount={row.sample}
                                                />
                                            </>
                                        ) : (
                                            t('gateways.rates.not_set')
                                        )}
                                    </p>
                                </div>
                            );
                        })}

                        <InputError message={errors.rates} />

                        {can.manage && (
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('gateways.rates.save')}
                            </Button>
                        )}
                    </form>
                </SectionCard>

                <SectionCard
                    title={t('gateways.rates.gateways_title')}
                    description={t('gateways.rates.gateways_description')}
                    contentClassName="p-0"
                >
                    <ul className="divide-border divide-y">
                        {gateways.map((gateway) => (
                            <li
                                key={gateway.name}
                                data-test={`rate-gateway-${gateway.name}`}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-6 py-4"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">
                                        {gateway.label}
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {t('gateways.rates.accepts', {
                                            currencies: missingFor(gateway),
                                        })}
                                    </p>
                                    <p className="text-sm">
                                        {gateway.offered_for_taka
                                            ? t('gateways.rates.offered', {
                                                  currency:
                                                      gateway.charge_currency ??
                                                      '',
                                              })
                                            : gateway.is_available
                                              ? t(
                                                    'gateways.rates.not_offered_rate',
                                                    {
                                                        currencies:
                                                            missingFor(gateway),
                                                    },
                                                )
                                              : t(
                                                    'gateways.rates.not_offered_off',
                                                )}
                                    </p>
                                </div>
                                {/* The label carries the state, never colour alone (§33.9). */}
                                <StatusPill
                                    tone={
                                        gateway.offered_for_taka
                                            ? 'success'
                                            : 'neutral'
                                    }
                                    label={
                                        gateway.offered_for_taka
                                            ? t('gateways.state.available')
                                            : t('gateways.state.disabled')
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                <SectionCard title={t('gateways.rates.how_title')}>
                    <ol className="text-muted-foreground list-decimal space-y-1.5 pl-5 text-sm">
                        <li>{t('gateways.rates.how_1')}</li>
                        <li>{t('gateways.rates.how_2')}</li>
                        <li>{t('gateways.rates.how_3')}</li>
                        <li>{t('gateways.rates.how_4')}</li>
                    </ol>
                </SectionCard>
            </PageContainer>
        </>
    );
}

ExchangeRates.layout = {
    breadcrumbs: [
        { title: 'settings.hub.title', href: settings() },
        { title: 'nav.payments', href: switches() },
        { title: 'gateways.rates.nav', href: exchangeRates() },
    ],
};
