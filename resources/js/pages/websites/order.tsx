import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteOrderController from '@/actions/App/Http/Controllers/Erp/WebsiteOrderController';
import { orderPaymentTone } from '@/lib/website-order';
import { index as websitesIndex } from '@/routes/websites';
import { index as ordersIndex } from '@/routes/websites/orders';
import WebsiteOrderReturns from '@/components/website-order-returns';
import type {
    WebsiteOrderAddress,
    WebsiteOrderDetail,
    WebsiteOrderReturn,
    WebsiteOrderReturnable,
} from '@/types/website-order';

type Props = {
    website: { id: string; name: string };
    order: WebsiteOrderDetail;
    returnable: WebsiteOrderReturnable;
    returns: WebsiteOrderReturn[];
    reasons: string[];
    can: { cancel: boolean; request_return: boolean };
};

/**
 * One order a partner's website took (§16.3, §18.4, P5-13, P6-8, P6-10).
 *
 * What was bought and for how much, who bought it and where it goes, where the
 * payment stands, how long the stock is held, and the timeline in the words the
 * customer was given. An order nobody has paid for can be cancelled here; one
 * that has been paid is refunded, not cancelled.
 */
export default function WebsiteOrder({
    website,
    order,
    returnable,
    returns,
    reasons,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const [confirming, setConfirming] = useState(false);

    const when = (value: string) => new Date(value).toLocaleString(locale);

    const address = (value: WebsiteOrderAddress) =>
        value === null
            ? '—'
            : [
                  value.line1,
                  value.line2,
                  value.city,
                  value.district,
                  value.postcode,
                  value.country,
              ]
                  .filter(Boolean)
                  .join(', ');

    return (
        <>
            <Head title={order.reference} />

            <PageContainer>
                <PageHeader
                    title={order.reference}
                    description={`${website.name} · ${order.storefront_reference ?? ''}`}
                    actions={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={ordersIndex(website.id)}>
                                {t('website.orders.title')}
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill
                        tone={order.status_tone}
                        label={t(`orders.statuses.${order.status}`)}
                    />
                    {order.payment_state && (
                        <StatusPill
                            tone={orderPaymentTone(order.payment_state)}
                            label={t(
                                `website.orders.payment_states.${order.payment_state}`,
                            )}
                        />
                    )}
                    <StatusPill
                        tone="neutral"
                        label={t(
                            `website.orders.payment_methods.${order.payment_method}`,
                        )}
                    />
                    <span className="text-muted-foreground text-xs">
                        {t('website.orders.placed')}: {when(order.placed_at)}
                    </span>
                </div>

                {order.confirmation && (
                    <SectionCard
                        title={t('website.orders.confirmation.title')}
                        description={t('website.orders.confirmation.hint')}
                    >
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.orders.confirmation.state')}
                                </dt>
                                <dd>
                                    <StatusPill
                                        tone={
                                            order.confirmation.state ===
                                            'confirmed'
                                                ? 'success'
                                                : order.confirmation.state ===
                                                    'cancelled'
                                                  ? 'danger'
                                                  : 'warning'
                                        }
                                        label={t(
                                            `website.orders.confirmation.states.${order.confirmation.state}`,
                                        )}
                                    />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.orders.confirmation.expires')}
                                </dt>
                                <dd>
                                    {order.confirmation.expires_at
                                        ? when(order.confirmation.expires_at)
                                        : '—'}
                                </dd>
                            </div>
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.orders.confirmation.code')}
                                </dt>
                                <dd>
                                    {order.confirmation.state !== 'pending'
                                        ? '—'
                                        : order.confirmation.code_outstanding
                                          ? t(
                                                'website.orders.confirmation.code_sent',
                                            )
                                          : t(
                                                'website.orders.confirmation.code_none',
                                            )}
                                </dd>
                            </div>
                        </dl>
                    </SectionCard>
                )}

                <SectionCard title={t('website.orders.customer')}>
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('website.orders.customer_name')}
                            </dt>
                            <dd>{order.customer_details.name ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('website.orders.customer_mobile')}
                            </dt>
                            <dd className="font-mono">
                                {order.customer_details.mobile ?? '—'}
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground text-xs">
                                {t('website.orders.shipping_address')}
                            </dt>
                            <dd>{address(order.shipping_address)}</dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-muted-foreground text-xs">
                                {t('website.orders.billing_address')}
                            </dt>
                            <dd>{address(order.billing_address)}</dd>
                        </div>
                        {order.customer_note && (
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">
                                    {t('website.orders.note')}
                                </dt>
                                <dd>{order.customer_note}</dd>
                            </div>
                        )}
                    </dl>
                </SectionCard>

                <SectionCard title={t('website.orders.items')}>
                    <ul className="divide-border divide-y">
                        {order.lines.map((line) => (
                            <li
                                key={line.id}
                                className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-0 space-y-1 text-sm">
                                    <div className="font-medium">
                                        {line.name}
                                    </div>
                                    <div className="text-muted-foreground font-mono text-xs">
                                        {line.sku}
                                        {line.variant
                                            ? ` · ${line.variant}`
                                            : ''}
                                    </div>
                                    {line.reservation && (
                                        <div className="text-muted-foreground text-xs">
                                            {t('website.orders.stock')}:{' '}
                                            {line.reservation.status_label}
                                            {line.reservation.status ===
                                                'active' &&
                                                ` · ${t('website.orders.held_until', { time: when(line.reservation.expires_at) })}`}
                                        </div>
                                    )}
                                </div>
                                <div className="text-right text-sm">
                                    <div>
                                        {line.quantity} ×{' '}
                                        <MoneyAmount amount={line.unit_price} />
                                    </div>
                                    <MoneyAmount amount={line.total} />
                                </div>
                            </li>
                        ))}
                    </ul>

                    <dl className="border-border mt-4 space-y-1 border-t pt-3 text-sm">
                        {(
                            [
                                ['subtotal', order.totals.subtotal],
                                ['discount', order.totals.discount],
                                ['delivery', order.totals.delivery],
                                ['tax', order.totals.tax],
                            ] as const
                        ).map(([key, amount]) => (
                            <div key={key} className="flex justify-between">
                                <dt className="text-muted-foreground">
                                    {t(`website.orders.totals.${key}`)}
                                </dt>
                                <dd>
                                    <MoneyAmount amount={amount} />
                                </dd>
                            </div>
                        ))}
                        <div className="flex justify-between font-semibold">
                            <dt>{t('website.orders.totals.total')}</dt>
                            <dd>
                                <MoneyAmount amount={order.totals.total} />
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard title={t('website.orders.timeline')}>
                    <ol className="divide-border divide-y text-sm">
                        {order.timeline.map((entry, index) => (
                            <li
                                key={`${entry.status}-${index}`}
                                className="space-y-1 py-2 first:pt-0 last:pb-0"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {t(`orders.statuses.${entry.status}`)}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {when(entry.at)}
                                    </span>
                                </div>
                                <p className="text-muted-foreground">
                                    {entry.note}
                                </p>
                            </li>
                        ))}
                    </ol>
                </SectionCard>

                <WebsiteOrderReturns
                    websiteId={website.id}
                    orderId={order.id}
                    returnable={returnable}
                    returns={returns}
                    reasons={reasons}
                    canRequest={can.request_return}
                />

                {can.cancel && (
                    <SectionCard
                        title={t('website.orders.cancel_title')}
                        description={t('website.orders.cancel_hint')}
                    >
                        {confirming ? (
                            <Form
                                {...WebsiteOrderController.cancel.form([
                                    website.id,
                                    order.id,
                                ])}
                                options={{ preserveScroll: true }}
                                className="flex flex-wrap items-center gap-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('website.orders.cancel_confirm')}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setConfirming(false)}
                                        >
                                            {t('website.orders.keep')}
                                        </Button>
                                        <InputError message={errors.order} />
                                    </>
                                )}
                            </Form>
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setConfirming(true)}
                            >
                                {t('website.orders.cancel')}
                            </Button>
                        )}
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

WebsiteOrder.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: websitesIndex(),
        },
    ],
};
