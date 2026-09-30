import { Form, Head } from '@inertiajs/react';
import { Check, Infinity as InfinityIcon, Minus } from 'lucide-react';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Money } from '@/lib/money';

type Feature = {
    key: string;
    label: string;
    value: boolean | number | string | null;
    type: 'boolean' | 'limit' | 'text';
};

type PackageOption = {
    slug: string;
    name: string;
    short_description: string | null;
    fee: Money;
    activation_total: Money;
    validity_days: number | null;
    required_deposit: Money;
    features: Feature[];
};

/**
 * Package selection and comparison (§8.2).
 *
 * Each card leads with the **total payable today**, not the package fee alone.
 * The registration fee is charged alongside it (§5.1), and showing only the
 * package price would understate every option and surprise the user at
 * checkout.
 */
export default function Packages({
    packages,
    selected_slug,
}: {
    packages: PackageOption[];
    selected_slug: string | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('package.choose.page_title')} />

            <PageContainer className="mx-auto max-w-5xl">
                <header className="space-y-1 text-center">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {t('package.choose.title')}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {t('package.choose.help')}
                    </p>
                </header>

                {packages.length === 0 ? (
                    <p className="text-muted-foreground py-10 text-center text-sm">
                        {t('package.choose.empty')}
                    </p>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {packages.map((option) => (
                            <PackageCard
                                key={option.slug}
                                option={option}
                                isSelected={option.slug === selected_slug}
                            />
                        ))}
                    </div>
                )}
            </PageContainer>
        </>
    );
}

function PackageCard({
    option,
    isSelected,
}: {
    option: PackageOption;
    isSelected: boolean;
}) {
    const { t } = useTranslation();

    return (
        <section
            className={cn(
                'bg-card flex flex-col rounded-xl border p-5',
                isSelected
                    ? 'border-brand ring-brand/20 ring-2'
                    : 'border-border',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <h2 className="font-semibold">{option.name}</h2>
                {isSelected && (
                    <StatusPill
                        tone="info"
                        label={t('package.choose.chosen')}
                    />
                )}
            </div>

            {option.short_description && (
                <p className="text-muted-foreground mt-1 text-sm">
                    {option.short_description}
                </p>
            )}

            <div className="mt-4 space-y-0.5">
                <MoneyAmount amount={option.activation_total} size="large" />
                <p className="text-muted-foreground text-xs">
                    {t('package.choose.payable_today')} ·{' '}
                    {t('package.choose.package_fee', {
                        fee: option.fee.formatted,
                    })}
                    {option.validity_days &&
                        ` ${t('package.choose.for_days', { days: option.validity_days })}`}
                </p>
            </div>

            <dl className="divide-border mt-4 flex-1 divide-y text-sm">
                {option.features.map((feature) => (
                    <div
                        key={feature.key}
                        className="flex items-center justify-between gap-3 py-2"
                    >
                        <dt className="text-muted-foreground text-xs">
                            {feature.label}
                        </dt>
                        <dd className="text-right text-sm font-medium">
                            <FeatureValue feature={feature} />
                        </dd>
                    </div>
                ))}
            </dl>

            <Form
                action={`/packages/${option.slug}/select`}
                method="post"
                className="mt-4"
            >
                {({ processing }) => (
                    <SubmitButton
                        processing={processing}
                        variant={isSelected ? 'outline' : 'default'}
                        className="w-full"
                    >
                        {isSelected
                            ? t('package.choose.continue')
                            : t('package.choose.choose_this', {
                                  name: option.name,
                              })}
                    </SubmitButton>
                )}
            </Form>
        </section>
    );
}

function FeatureValue({ feature }: { feature: Feature }) {
    const { t } = useTranslation();

    if (feature.type === 'boolean') {
        return feature.value ? (
            <span className="text-success inline-flex items-center gap-1">
                <Check className="size-3.5" aria-hidden="true" />
                <span className="sr-only">{t('package.choose.included')}</span>
            </span>
        ) : (
            <span className="text-muted-foreground inline-flex items-center gap-1">
                <Minus className="size-3.5" aria-hidden="true" />
                <span className="sr-only">
                    {t('package.choose.not_included')}
                </span>
            </span>
        );
    }

    if (feature.type === 'limit') {
        // Null means unlimited; zero means none. Showing "0" for unlimited
        // would be exactly backwards.
        return feature.value === null ? (
            <span className="inline-flex items-center gap-1">
                <InfinityIcon className="size-3.5" aria-hidden="true" />
                <span className="sr-only">{t('package.choose.unlimited')}</span>
            </span>
        ) : (
            <span className="tabular-nums">{String(feature.value)}</span>
        );
    }

    return <span className="capitalize">{String(feature.value ?? '—')}</span>;
}
