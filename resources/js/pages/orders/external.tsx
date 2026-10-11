import { Head, Link } from '@inertiajs/react';
import DetailList, { DetailItem } from '@/components/detail-list';
import MoneyAmount from '@/components/money-amount';
import Notice from '@/components/notice';
import OrderTimeline from '@/components/order-timeline';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { show as customerShow } from '@/routes/customers';
import { index as ordersIndex } from '@/routes/orders';
import { show as storeShow } from '@/routes/stores';
import type { ExternalOrderDetail, TimelineEntry } from '@/types/stores';

type Props = {
    order: ExternalOrderDetail;
    timeline: TimelineEntry[];
};

const addressParts = ['line', 'area', 'city', 'district', 'postcode'] as const;

/**
 * An order one of the partner's own stores took, as the store reported it
 * (D29).
 *
 * Read-only by design: the store is the source of truth, so nothing here
 * changes the order, its payment or its delivery. Anything the store did not
 * send says so rather than being filled in.
 */
export default function ExternalOrder({ order, timeline }: Props) {
    const { t, locale } = useTranslation();
    const when = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';
    const missing = (
        <span className="text-muted-foreground font-normal">
            {t('orders.external.not_given')}
        </span>
    );

    const address = (value: Record<string, string | null> | null) => {
        if (value === null) {
            return missing;
        }

        const lines = addressParts
            .map((part) => value[part])
            .filter((line): line is string => !!line);

        return (
            <div className="space-y-0.5 font-normal">
                {value.name && <div className="font-medium">{value.name}</div>}
                {value.phone && <div>{value.phone}</div>}
                {lines.length > 0 ? <div>{lines.join(', ')}</div> : missing}
            </div>
        );
    };

    return (
        <>
            <Head
                title={t('orders.external.title', { number: order.number })}
            />

            <PageContainer>
                <PageHeader
                    title={t('orders.external.title', { number: order.number })}
                    description={`${order.store.name} · ${when(order.placed_at)}`}
                    back={{
                        href: ordersIndex(),
                        label: t('orders.external.back'),
                    }}
                    meta={
                        <div className="flex flex-wrap gap-2">
                            <StatusPill
                                tone={order.status_tone}
                                label={order.status_label}
                            />
                            {order.payment_label && (
                                <StatusPill
                                    tone={order.payment_tone}
                                    label={order.payment_label}
                                />
                            )}
                        </div>
                    }
                />

                <Notice tone="info">
                    {t('orders.external.read_only', {
                        platform: order.platform,
                    })}
                </Notice>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <SectionCard
                            title={t('orders.external.sections.overview')}
                        >
                            <DetailList columns={2}>
                                <DetailItem
                                    label={t('orders.external.fields.number')}
                                >
                                    {order.number}
                                </DetailItem>
                                <DetailItem
                                    label={t('orders.external.fields.source')}
                                >
                                    {order.platform}
                                </DetailItem>
                                <DetailItem label={t('orders.external.store')}>
                                    <Link
                                        href={storeShow(order.store.id)}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {order.store.name}
                                    </Link>
                                </DetailItem>
                                <DetailItem label={t('orders.external.placed')}>
                                    {when(order.placed_at)}
                                </DetailItem>
                                <DetailItem
                                    label={t('orders.external.fields.status')}
                                >
                                    <StatusPill
                                        tone={order.status_tone}
                                        label={order.status_label}
                                    />
                                </DetailItem>
                                <DetailItem
                                    label={t(
                                        'orders.external.fields.payment_status',
                                    )}
                                >
                                    {order.payment_label ? (
                                        <StatusPill
                                            tone={order.payment_tone}
                                            label={order.payment_label}
                                        />
                                    ) : (
                                        missing
                                    )}
                                </DetailItem>
                                <DetailItem
                                    label={t('orders.external.store_updated')}
                                >
                                    {when(order.source_updated_at)}
                                </DetailItem>
                                <DetailItem label={t('orders.external.synced')}>
                                    {when(order.last_synced_at)}
                                </DetailItem>
                                {order.cancel_reason && (
                                    <DetailItem
                                        label={t(
                                            'orders.external.fields.cancel_reason',
                                        )}
                                        span
                                    >
                                        {order.cancel_reason}
                                    </DetailItem>
                                )}
                            </DetailList>
                        </SectionCard>

                        <SectionCard
                            title={t('orders.external.sections.products')}
                        >
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="py-2 pr-3 font-medium">
                                                {t(
                                                    'orders.external.sections.products',
                                                )}
                                            </th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                {t(
                                                    'orders.external.fields.quantity',
                                                )}
                                            </th>
                                            <th className="py-2 pr-3 text-right font-medium">
                                                {t(
                                                    'orders.external.fields.unit_price',
                                                )}
                                            </th>
                                            <th className="py-2 text-right font-medium">
                                                {t(
                                                    'orders.external.fields.line_total',
                                                )}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {order.lines.map((line) => (
                                            <tr key={line.line}>
                                                <td className="py-2 pr-3">
                                                    <div className="font-medium">
                                                        {line.name}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {line.sku &&
                                                            `${t('orders.external.fields.sku')}: ${line.sku}`}
                                                        {line.variant &&
                                                            ` · ${line.variant}`}
                                                    </div>
                                                </td>
                                                <td className="tabular py-2 pr-3 text-right">
                                                    {line.quantity.toLocaleString(
                                                        locale,
                                                    )}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    <MoneyAmount
                                                        amount={line.unit_price}
                                                        direction="neutral"
                                                    />
                                                </td>
                                                <td className="py-2 text-right">
                                                    <MoneyAmount
                                                        amount={line.total}
                                                        direction="neutral"
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </SectionCard>

                        <SectionCard
                            title={t('orders.timeline.title')}
                            description={t('orders.timeline.description')}
                        >
                            <OrderTimeline entries={timeline} />
                        </SectionCard>
                    </div>

                    <div className="space-y-6">
                        <SectionCard
                            title={t('orders.external.sections.customer')}
                        >
                            <DetailList>
                                <DetailItem
                                    label={t('orders.external.fields.name')}
                                >
                                    {order.customer.name ?? missing}
                                </DetailItem>
                                <DetailItem
                                    label={t('orders.external.fields.phone')}
                                >
                                    {order.customer.phone ?? missing}
                                </DetailItem>
                                <DetailItem
                                    label={t('orders.external.fields.email')}
                                >
                                    {order.customer.email ?? missing}
                                </DetailItem>
                                <DetailItem
                                    label={t(
                                        'orders.external.sections.shipping',
                                    )}
                                >
                                    {address(order.shipping_address)}
                                </DetailItem>
                                {order.billing_address && (
                                    <DetailItem
                                        label={t(
                                            'orders.external.sections.billing',
                                        )}
                                    >
                                        {address(order.billing_address)}
                                    </DetailItem>
                                )}
                                {order.customer.id && (
                                    <DetailItem
                                        label={t(
                                            'orders.external.fields.customer_profile',
                                        )}
                                    >
                                        <Link
                                            href={customerShow([
                                                'external',
                                                order.customer.id,
                                            ])}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {order.customer.name ??
                                                t('orders.customers.unnamed')}
                                        </Link>
                                    </DetailItem>
                                )}
                            </DetailList>
                        </SectionCard>

                        <SectionCard
                            title={t('orders.external.sections.payment')}
                        >
                            <dl className="space-y-2 text-sm">
                                <SummaryRow
                                    label={t('orders.external.fields.subtotal')}
                                >
                                    <MoneyAmount
                                        amount={order.totals.subtotal}
                                        direction="neutral"
                                    />
                                </SummaryRow>
                                <SummaryRow
                                    label={
                                        order.coupon_code
                                            ? `${t('orders.external.fields.discount')} (${t('orders.external.fields.coupon', { code: order.coupon_code })})`
                                            : t(
                                                  'orders.external.fields.discount',
                                              )
                                    }
                                >
                                    <MoneyAmount
                                        amount={order.totals.discount}
                                        direction="debit"
                                    />
                                </SummaryRow>
                                <SummaryRow
                                    label={t('orders.external.fields.shipping')}
                                >
                                    <MoneyAmount
                                        amount={order.totals.shipping}
                                        direction="neutral"
                                    />
                                </SummaryRow>
                                <SummaryRow
                                    label={t('orders.external.fields.tax')}
                                >
                                    <MoneyAmount
                                        amount={order.totals.tax}
                                        direction="neutral"
                                    />
                                </SummaryRow>
                                <SummaryRow
                                    label={t('orders.external.fields.total')}
                                    strong
                                >
                                    <MoneyAmount
                                        amount={order.totals.total}
                                        direction="neutral"
                                    />
                                </SummaryRow>
                                <SummaryRow
                                    label={t('orders.external.fields.paid')}
                                >
                                    {order.totals.paid ? (
                                        <MoneyAmount
                                            amount={order.totals.paid}
                                            direction="neutral"
                                        />
                                    ) : (
                                        missing
                                    )}
                                </SummaryRow>
                                <SummaryRow
                                    label={t(
                                        'orders.external.fields.payment_method',
                                    )}
                                >
                                    {order.payment_method ? (
                                        <span className="uppercase">
                                            {order.payment_method}
                                        </span>
                                    ) : (
                                        missing
                                    )}
                                </SummaryRow>
                            </dl>
                        </SectionCard>

                        <SectionCard
                            title={t('orders.external.sections.delivery')}
                        >
                            <p className="text-muted-foreground text-sm">
                                {t('orders.external.no_delivery')}
                            </p>
                        </SectionCard>

                        {order.note && (
                            <SectionCard
                                title={t('orders.external.sections.note')}
                            >
                                <p className="text-sm whitespace-pre-line">
                                    {order.note}
                                </p>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

function SummaryRow({
    label,
    children,
    strong = false,
}: {
    label: string;
    children: React.ReactNode;
    strong?: boolean;
}) {
    return (
        <div
            className={`flex items-center justify-between gap-3 ${strong ? 'border-t pt-2 font-semibold' : ''}`}
        >
            <dt className={strong ? '' : 'text-muted-foreground'}>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

ExternalOrder.layout = {
    breadcrumbs: [{ title: 'nav.orders', href: ordersIndex() }],
};
