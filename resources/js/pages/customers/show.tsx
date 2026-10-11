import { Head, Link } from '@inertiajs/react';
import DetailList, { DetailItem } from '@/components/detail-list';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatCard, { StatCardGrid } from '@/components/stat-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/customers';
import type { BusinessCustomerDetail } from '@/types/stores';

type Props = { customer: BusinessCustomerDetail };

/**
 * One customer of one store, and what they ordered there (D29).
 */
export default function CustomerShow({ customer }: Props) {
    const { t, locale } = useTranslation();
    const name = customer.name ?? t('orders.customers.unnamed');
    const count = (value: number) => value.toLocaleString(locale);

    return (
        <>
            <Head title={name} />

            <PageContainer>
                <PageHeader
                    title={name}
                    description={`${customer.store} · ${customer.kind_label}`}
                    back={{ href: index(), label: t('orders.customers.back') }}
                />

                <StatCardGrid>
                    <StatCard
                        size="compact"
                        label={t('orders.customers.stats.total')}
                        value={count(customer.stats.total)}
                    />
                    <StatCard
                        size="compact"
                        tone="success"
                        label={t('orders.customers.stats.delivered')}
                        value={count(customer.stats.delivered)}
                    />
                    <StatCard
                        size="compact"
                        tone="neutral"
                        label={t('orders.customers.stats.cancelled')}
                        value={count(customer.stats.cancelled)}
                    />
                    <StatCard
                        size="compact"
                        tone="warning"
                        label={t('orders.customers.stats.returned')}
                        value={count(customer.stats.returned)}
                    />
                </StatCardGrid>

                <div className="grid gap-6 lg:grid-cols-3">
                    <SectionCard title={t('orders.customers.contact')}>
                        <DetailList>
                            <DetailItem
                                label={t('orders.external.fields.name')}
                            >
                                {name}
                            </DetailItem>
                            <DetailItem
                                label={t('orders.external.fields.phone')}
                            >
                                {customer.phone ?? '—'}
                            </DetailItem>
                            <DetailItem
                                label={t('orders.external.fields.email')}
                            >
                                {customer.email ?? '—'}
                            </DetailItem>
                            <DetailItem
                                label={t('orders.customers.columns.store')}
                            >
                                {customer.store}
                            </DetailItem>
                        </DetailList>
                        <p className="text-muted-foreground mt-3 text-xs">
                            {t('orders.customers.separate_note')}
                        </p>
                    </SectionCard>

                    <SectionCard
                        title={t('orders.customers.orders')}
                        className="lg:col-span-2"
                    >
                        {customer.orders.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                {t('orders.customers.no_orders')}
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {customer.orders.map((order) => (
                                    <li
                                        key={order.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                                    >
                                        <div className="min-w-0 text-sm">
                                            <Link
                                                href={order.href}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {order.number}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">
                                                {new Date(
                                                    order.placed_at,
                                                ).toLocaleString(locale)}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <StatusPill
                                                tone={order.status_tone}
                                                label={order.status_label}
                                            />
                                            <MoneyAmount
                                                amount={order.total}
                                                direction="neutral"
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </div>
            </PageContainer>
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'nav.customers', href: index() }],
};
