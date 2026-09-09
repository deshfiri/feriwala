import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/subscription/change';
import type { Money } from '@/lib/money';

type Excess = {
    feature: string;
    label: string;
    current: number;
    new_limit: number;
    must_remove: number;
};

type Option = {
    slug: string;
    package: string;
    direction: string;
    direction_label: string;
    effective_from: string;
    expires_at: string | null;
    validity_days: number | null;
    credit: Money;
    gross_fee: Money;
    additional_deposit: Money;
    payable: Money;
    is_allowed: boolean;
    downgrade: {
        is_allowed: boolean;
        total_to_remove: number;
        excess: Excess[];
    };
    quote: { is_payable: boolean };
};

type Props = {
    current: { package: string | null; expires_at: string | null };
    options: Option[];
    gateways: { name: string; label: string }[];
};

/**
 * Moving to another package (§8.3).
 *
 * Each option states what it would actually do — up or down, what it costs
 * after the credit for the term already paid for, and when it applies. §8.3
 * makes those the terms of the change, so showing a price without an effective
 * date would be quoting half of it.
 *
 * A plan the account is over is shown, not hidden, with the three figures D16
 * requires: what they have, what the plan allows, and how many must go. "You
 * cannot downgrade" gives somebody no way to act.
 *
 * No figure is computed here. They arrive calculated and this renders them
 * (§36.1).
 */
export default function PackageChange({ current, options, gateways }: Props) {
    const { t, locale } = useTranslation();
    const [choosing, setChoosing] = useState<string | null>(null);
    const gateway = gateways[0]?.name ?? '';

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    return (
        <>
            <Head title={t('package.change.title')} />

            <h1 className="sr-only">{t('package.change.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('package.change.title')}
                    description={t('package.change.description')}
                />

                <p className="text-muted-foreground text-sm">
                    {t('package.change.current')}: {current.package}
                    {current.expires_at !== null &&
                        ` · ${t('package.subscription.expires')} ${date(current.expires_at)}`}
                </p>

                {options.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {t('package.change.none')}
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {options.map((option) => (
                            <li
                                key={option.slug}
                                className="bg-card space-y-3 rounded-xl border p-4"
                            >
                                <header className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="font-medium">
                                            {option.package}
                                        </h2>
                                        <p className="text-muted-foreground text-sm">
                                            {t('package.change.effective')}{' '}
                                            {date(option.effective_from)}
                                        </p>
                                    </div>

                                    <StatusPill
                                        tone={
                                            option.direction === 'upgrade'
                                                ? 'success'
                                                : 'neutral'
                                        }
                                        label={option.direction_label}
                                    />
                                </header>

                                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt className="text-muted-foreground text-xs">
                                            {t('package.change.line', {
                                                package: option.package,
                                            })}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={option.gross_fee}
                                            />
                                        </dd>
                                    </div>

                                    {option.credit.minor_units > 0 && (
                                        <div>
                                            <dt className="text-muted-foreground text-xs">
                                                {t('package.change.credit')}
                                            </dt>
                                            <dd>
                                                <MoneyAmount
                                                    amount={option.credit}
                                                    direction="credit"
                                                    showSign
                                                />
                                            </dd>
                                        </div>
                                    )}

                                    <div>
                                        <dt className="text-muted-foreground text-xs">
                                            {t('package.change.payable')}
                                        </dt>
                                        <dd className="font-medium">
                                            <MoneyAmount
                                                amount={option.payable}
                                            />
                                        </dd>
                                    </div>

                                    {option.additional_deposit.minor_units >
                                        0 && (
                                        <div>
                                            <dt className="text-muted-foreground text-xs">
                                                {t('package.change.deposit')}
                                            </dt>
                                            <dd>
                                                <MoneyAmount
                                                    amount={
                                                        option.additional_deposit
                                                    }
                                                />
                                            </dd>
                                        </div>
                                    )}
                                </dl>

                                {!option.is_allowed && (
                                    <div className="space-y-1 rounded-lg border border-dashed p-3 text-sm">
                                        <p className="font-medium">
                                            {t('package.change.blocked')}
                                        </p>
                                        <ul className="text-muted-foreground space-y-0.5">
                                            {option.downgrade.excess.map(
                                                (item) => (
                                                    <li key={item.feature}>
                                                        {t(
                                                            'package.change.must_remove',
                                                            {
                                                                label: item.label,
                                                                current:
                                                                    item.current,
                                                                limit: item.new_limit,
                                                                count: item.must_remove,
                                                            },
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}

                                {option.is_allowed && (
                                    <Form
                                        {...store.form()}
                                        options={{ preserveScroll: true }}
                                        onSubmit={() =>
                                            setChoosing(option.slug)
                                        }
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="package"
                                                    value={option.slug}
                                                />
                                                {/* One gateway is chosen for
                                                    the whole page; the server
                                                    validates it against what is
                                                    actually available. */}
                                                <input
                                                    type="hidden"
                                                    name="gateway"
                                                    value={gateway}
                                                />

                                                {errors.package && (
                                                    <p className="text-danger mb-2 text-sm font-medium">
                                                        {errors.package}
                                                    </p>
                                                )}

                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={
                                                        processing &&
                                                        choosing === option.slug
                                                    }
                                                >
                                                    {t('package.change.choose')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
