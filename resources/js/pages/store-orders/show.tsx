import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import StoreOrderTimeline from '@/components/store-order-timeline';
import type { StoreOrderTimelineData } from '@/components/store-order-timeline';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import type { StoreOrderRow } from '@/components/store-order-list';

export type StoreOrderDetail = StoreOrderRow & {
    customer_phone: string | null;
    shipping_address: Record<string, string | null> | null;
    cancellation_reason: string | null;
    items: {
        sku: string;
        name: string;
        variant: string | null;
        quantity: number;
        line_total: Money;
    }[];
};

type Props = {
    order: StoreOrderDetail;
    timeline: StoreOrderTimelineData;
    can: { advance: boolean; cancel: boolean };
    /** Where the page's links and forms go; `advance` is absent for a Partner, who has no such button. */
    urls: { back: string; cancel: string; advance: string | null };
};

/**
 * One Store Order: its progress, its payment (apart from its progress), what was
 * ordered, and — for staff — the single next action and the way to cancel.
 *
 * The Partner sees all of it and can cancel only while the order is Pending.
 * Neither side ever picks a status from a list.
 */
export default function StoreOrderShow({ order, timeline, can, urls }: Props) {
    const { t } = useTranslation();
    const cancelForm = useForm({ reason: '' });
    const actionForm = useForm({ note: '' });
    const cancelUrl = urls.cancel;

    const advance = (event: FormEvent) => {
        event.preventDefault();

        if (timeline.next_action && urls.advance) {
            actionForm.post(
                urls.advance.replace('__action__', timeline.next_action.key),
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancelForm.post(cancelUrl, { preserveScroll: true });
    };

    return (
        <>
            <Head title={order.reference} />

            <PageContainer>
                <PageHeader
                    title={order.reference}
                    description={`${order.storefront.name ?? ''}${
                        order.partner ? ` · ${order.partner.name}` : ''
                    }`}
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <Link href={urls.back}>
                                <ArrowLeft aria-hidden="true" />
                                {t('store_orders.back')}
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="space-y-4">
                        <SectionCard title={t('store_orders.timeline')}>
                            <StoreOrderTimeline timeline={timeline} />
                        </SectionCard>

                        <SectionCard title={t('store_orders.items')}>
                            <ul className="divide-y">
                                {order.items.map((item) => (
                                    <li
                                        key={item.sku}
                                        className="flex justify-between gap-3 py-2 text-sm"
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {item.name}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {item.variant ?? item.sku} ×{' '}
                                                {item.quantity}
                                            </span>
                                        </span>
                                        <MoneyAmount amount={item.line_total} />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    </div>

                    <div className="space-y-4">
                        {can.advance && timeline.next_action && (
                            <SectionCard title={t('store_orders.next_step')}>
                                <form onSubmit={advance} className="space-y-3">
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={actionForm.processing}
                                        data-test="next-action"
                                    >
                                        {timeline.next_action.label}
                                    </Button>
                                    <InputError
                                        message={
                                            (
                                                actionForm.errors as Record<
                                                    string,
                                                    string
                                                >
                                            ).action
                                        }
                                    />
                                </form>
                            </SectionCard>
                        )}

                        <SectionCard title={t('store_orders.payment')}>
                            <StatusPill
                                tone={
                                    order.payment === 'paid'
                                        ? 'success'
                                        : 'neutral'
                                }
                                label={
                                    order.payment
                                        ? t(
                                              `orders.admin.payment_statuses.${order.payment}`,
                                          )
                                        : t('store_orders.no_payment')
                                }
                            />
                            <p className="text-muted-foreground mt-2 text-xs">
                                {t('store_orders.payment_apart')}
                            </p>
                        </SectionCard>

                        <SectionCard title={t('store_orders.delivery')}>
                            <dl className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        {t('store_orders.courier')}
                                    </dt>
                                    <dd>
                                        {t(
                                            `store_orders.axes.${order.courier}`,
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        {t('store_orders.delivery_status')}
                                    </dt>
                                    <dd>
                                        {t(
                                            `store_orders.axes.${order.delivery}`,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </SectionCard>

                        {order.cancellation_reason && (
                            <SectionCard title={t('store_orders.cancellation')}>
                                <p className="text-sm">
                                    {order.cancellation_reason}
                                </p>
                            </SectionCard>
                        )}

                        {can.cancel && cancelUrl !== '' && (
                            <SectionCard title={t('store_orders.cancel_title')}>
                                <form onSubmit={cancel} className="space-y-2">
                                    <Input
                                        value={cancelForm.data.reason}
                                        onChange={(event) =>
                                            cancelForm.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                        placeholder={t(
                                            'store_orders.cancel_reason',
                                        )}
                                        aria-label={t(
                                            'store_orders.cancel_reason',
                                        )}
                                        maxLength={1000}
                                    />
                                    <InputError
                                        message={cancelForm.errors.reason}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={cancelForm.processing}
                                    >
                                        {t('store_orders.cancel')}
                                    </Button>
                                </form>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>
        </>
    );
}
