import { Head, Link } from '@inertiajs/react';
import { PackageOpen } from 'lucide-react';
import Heading from '@/components/heading';
import MoneyAmount from '@/components/money-amount';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index as choosePackage } from '@/routes/packages';
import type { AccountSubscriptionRow } from '@/types';

type Props = {
    current: AccountSubscriptionRow | null;
    history: AccountSubscriptionRow[];
};

/**
 * The account's own subscription (§8.2, §8.4).
 *
 * Everything here comes from the terms **captured at purchase**, not from the
 * package as it stands today. This is the one screen where the difference is
 * visible to the person it affects: a limit shown here is the limit they are
 * held to, and showing the current package's would be telling them about
 * somebody else's plan.
 */
export default function Subscription({ current, history }: Props) {
    const { t } = useTranslation();

    /*
     * Terms other than the one on show. Filtered before it is counted, because
     * an account whose only term was cancelled has no current term at all — and
     * a length check on the unfiltered list would hide the very row that
     * explains why the page is otherwise empty.
     */
    const previous = history.filter((row) => row.id !== current?.id);

    return (
        <>
            <Head title={t('package.subscription.title')} />

            <h1 className="sr-only">{t('package.subscription.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('package.subscription.title')}
                    description={t('package.subscription.description')}
                />

                {current === null ? (
                    <EmptyState
                        icon={PackageOpen}
                        title={t('package.subscription.none_title')}
                        description={t('package.subscription.none_description')}
                        action={
                            <Button asChild size="sm">
                                <Link href={choosePackage()}>
                                    {t('package.subscription.choose')}
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <CurrentTerm subscription={current} />
                )}

                {previous.length > 0 && (
                    <section className="space-y-3">
                        <Heading
                            variant="small"
                            title={t('package.subscription.history')}
                        />

                        <ul className="bg-card divide-border divide-y rounded-xl border text-sm">
                            {previous.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate">
                                            {row.package}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            <Dates subscription={row} />
                                        </p>
                                    </div>

                                    <StatusPill
                                        tone={row.status_tone}
                                        label={row.status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function CurrentTerm({
    subscription,
}: {
    subscription: AccountSubscriptionRow;
}) {
    const { t } = useTranslation();

    return (
        <section className="bg-card space-y-4 rounded-xl border p-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-medium">{subscription.package}</h2>
                    <p className="text-muted-foreground text-sm">
                        <Dates subscription={subscription} />
                    </p>
                </div>

                {/* Never colour alone (§33.9): the pill carries its label. */}
                <StatusPill
                    tone={subscription.status_tone}
                    label={subscription.status_label}
                />
            </header>

            {subscription.in_grace_period && (
                <p className="text-sm font-medium" role="status">
                    {t('package.subscription.in_grace')}
                </p>
            )}

            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                {subscription.paid && (
                    <Detail label={t('package.subscription.paid')}>
                        {/* Formatted server-side, never computed here (§36.1). */}
                        <MoneyAmount amount={subscription.paid} />
                    </Detail>
                )}

                {subscription.renewal_fee && (
                    <Detail label={t('package.subscription.renewal')}>
                        <MoneyAmount amount={subscription.renewal_fee} />
                        {subscription.renewal_frequency && (
                            <span className="text-muted-foreground">
                                {' · '}
                                {t(
                                    `package.frequency.${subscription.renewal_frequency}`,
                                )}
                            </span>
                        )}
                    </Detail>
                )}

                <Detail label={t('package.subscription.source')}>
                    {subscription.source_label}
                </Detail>
            </dl>

            {subscription.features && subscription.features.length > 0 && (
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {t('package.subscription.entitlements')}
                    </h3>

                    {/* Already on a card, so no surface colour of its own. */}
                    <ul className="divide-border divide-y rounded-xl border text-sm">
                        {subscription.features.map((feature) => (
                            <li
                                key={feature.key}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <span>{feature.label}</span>
                                <span className="text-muted-foreground">
                                    <FeatureValue feature={feature} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/**
 * A limit of null is **unlimited** and zero is **none at all** — §8.1 keeps
 * those apart, so the reader must see them apart too.
 */
function FeatureValue({
    feature,
}: {
    feature: NonNullable<AccountSubscriptionRow['features']>[number];
}) {
    const { t } = useTranslation();

    if (feature.type === 'boolean') {
        return (
            <>
                {feature.value
                    ? t('package.subscription.included')
                    : t('package.subscription.not_included')}
            </>
        );
    }

    if (feature.type === 'limit') {
        if (feature.value === null) {
            return <>{t('package.subscription.unlimited')}</>;
        }

        return feature.value === 0 ? (
            <>{t('package.subscription.none')}</>
        ) : (
            <>{feature.value}</>
        );
    }

    return <>{feature.value ?? '—'}</>;
}

function Dates({ subscription }: { subscription: AccountSubscriptionRow }) {
    const { t, locale } = useTranslation();

    const format = (value: string) =>
        new Date(value).toLocaleDateString(locale, {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });

    const parts = [
        subscription.started_at &&
            `${t('package.subscription.started')} ${format(subscription.started_at)}`,
        subscription.expires_at
            ? `${t('package.subscription.expires')} ${format(subscription.expires_at)}`
            : subscription.started_at && t('package.subscription.no_expiry'),
        subscription.days_remaining !== null &&
            subscription.days_remaining > 0 &&
            t('package.subscription.days_left', {
                count: subscription.days_remaining,
            }),
    ].filter(Boolean);

    return <>{parts.join(' · ')}</>;
}
