import { Form, Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    CreditCard,
    FileText,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import WholesaleOrderController from '@/actions/App/Http/Controllers/Erp/WholesaleOrderController';
import ConfirmDialog from '@/components/forms/confirm-dialog';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { isPositive } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as invoiceShow } from '@/routes/subscription/invoices';
import { index } from '@/routes/wholesale/orders';
import type {
    WholesaleOrderAbilities,
    WholesaleOrderDetail,
} from '@/types/orders';
import type { CheckoutAddress } from '@/types/wholesale';

type Props = {
    order: WholesaleOrderDetail;
};

/**
 * One wholesale order, followed by the account that placed it (§10.2, P4-9–P4-12).
 *
 * The first thing on the page is where the order stands and what, if anything,
 * the buyer can expect next — waiting for payment with the stock held, the
 * payment being confirmed, paid, held for review, or cancelled — in words, not
 * colour alone, with the actions the server allows now. Everything below it is
 * the order as it was recorded.
 */
export default function WholesaleOrder({
    order,
    can = { pay: false, cancel: false },
}: Props & { can?: WholesaleOrderAbilities }) {
    const { t, locale } = useTranslation();
    const title = t('orders.order_title', { reference: order.reference });
    const placed = [
        t('orders.placed_on', {
            date: new Date(order.placed_at).toLocaleString(locale),
        }),
        order.placed_by
            ? t('orders.placed_by', { name: order.placed_by })
            : null,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <>
            <Head title={title} />

            <PageContainer>
                <Link
                    href={index()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('orders.back')}
                </Link>

                <PageHeader
                    title={title}
                    description={placed}
                    actions={
                        <StatusPill
                            tone={order.status_tone}
                            label={t(`orders.statuses.${order.status}`)}
                        />
                    }
                />

                <OrderState order={order} />

                {(can.pay || can.cancel) && (
                    <OrderActions order={order} can={can} />
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard
                            title={t('orders.sections.items')}
                            contentClassName="p-0"
                        >
                            <ul className="divide-border divide-y">
                                {order.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                    >
                                        <div className="min-w-0 space-y-0.5">
                                            <p className="font-medium">
                                                {line.name}
                                            </p>
                                            <p className="text-muted-foreground font-mono text-xs">
                                                {line.sku}
                                                {line.variant
                                                    ? ` · ${line.variant}`
                                                    : ''}
                                            </p>
                                            <p className="text-muted-foreground text-xs tabular-nums">
                                                {t(
                                                    'orders.lines.quantity_each',
                                                    {
                                                        quantity: line.quantity,
                                                        amount: line.unit_price
                                                            .formatted,
                                                    },
                                                )}
                                                {isPositive(line.discount) &&
                                                    ` · ${t('orders.lines.discount', { amount: line.discount.formatted })}`}
                                                {isPositive(line.tax) &&
                                                    ` · ${t('orders.lines.tax', { amount: line.tax.formatted })}`}
                                            </p>
                                        </div>
                                        <MoneyAmount
                                            amount={line.total}
                                            className="font-semibold"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <div className="grid gap-6 md:grid-cols-2">
                            <AddressCard
                                title={t('orders.sections.billing')}
                                address={order.addresses.billing}
                            />
                            <AddressCard
                                title={t('orders.sections.shipping')}
                                address={order.addresses.shipping}
                            />
                        </div>

                        {order.customer_note && (
                            <SectionCard title={t('orders.sections.note')}>
                                <p className="text-sm whitespace-pre-line">
                                    {order.customer_note}
                                </p>
                            </SectionCard>
                        )}

                        {order.intended_resale_channel && (
                            <SectionCard title={t('orders.resale.section')}>
                                <p className="text-sm">
                                    {t(
                                        `orders.resale.channels.${order.intended_resale_channel}`,
                                    )}
                                </p>
                            </SectionCard>
                        )}

                        <Timeline order={order} />
                    </div>

                    <div className="space-y-6">
                        <Summary order={order} />
                        <PaymentCard order={order} />
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

/**
 * Where the order stands, and what the buyer can expect next.
 */
function OrderState({ order }: Props) {
    const { t, locale } = useTranslation();
    const at = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '';

    let tone: 'info' | 'success' | 'warning' | 'danger' = 'info';
    let Icon: LucideIcon = Clock;
    let heading = '';
    let body = '';

    if (order.status === 'payment_pending') {
        if (order.payment_state === 'confirming') {
            heading = t('orders.states.confirming_title');
            body = t('orders.states.confirming_body', {
                gateway: order.payment?.gateway ?? '',
            });
        } else {
            const held =
                order.stock.state === 'held' &&
                order.stock.held_until !== null &&
                order.payment?.window_open !== false;

            tone = held ? 'info' : 'warning';
            heading = t('orders.states.awaiting_title');
            body = held
                ? t('orders.states.awaiting_held', {
                      time: at(order.stock.held_until),
                  })
                : t('orders.states.awaiting_lapsed');
        }
    } else if (order.status === 'on_hold') {
        tone = 'warning';
        Icon = AlertTriangle;
        heading = t('orders.states.on_hold_title');
        body = t('orders.states.on_hold_body');
    } else if (order.status === 'cancelled') {
        if (order.payment_state === 'reconciliation') {
            tone = 'warning';
            Icon = AlertTriangle;
            heading = t('orders.states.reconciliation_title');
            body = t('orders.states.reconciliation_body');
        } else {
            tone = 'danger';
            Icon = XCircle;
            heading = t('orders.states.cancelled_title');
            body = t('orders.states.cancelled_body');
        }
    } else if (order.paid_at) {
        tone = 'success';
        Icon = CheckCircle2;
        heading = t('orders.states.paid_title');
        body = t('orders.states.paid_body', { date: at(order.paid_at) });
    }

    if (heading === '') {
        return null;
    }

    return (
        <div
            role="status"
            data-state={order.status}
            className={cn(
                'flex gap-3 rounded-xl border p-4',
                tone === 'success' && 'border-success bg-success-subtle',
                tone === 'warning' && 'border-warning bg-warning-subtle',
                tone === 'danger' && 'border-danger bg-danger-subtle',
                tone === 'info' && 'border-info bg-info-subtle',
            )}
        >
            <Icon className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div className="space-y-1">
                <p className="font-medium">{heading}</p>
                <p className="text-muted-foreground text-sm">{body}</p>
            </div>
        </div>
    );
}

/**
 * Continue to payment, or cancel before paying — only what the server allows now.
 *
 * Paying sends nothing but the order: the amount and gateway were fixed when it
 * was placed. Cancelling asks first, because it gives the held stock back.
 */
function OrderActions({
    order,
    can,
}: Props & { can: WholesaleOrderAbilities }) {
    const { t } = useTranslation();
    const cancelForm = useForm({});
    // The refusal comes back under the order, not under a field of this empty form.
    const cancelErrors: Partial<Record<string, string>> = cancelForm.errors;

    return (
        <div className="flex flex-wrap items-start gap-3">
            {can.pay && (
                <Form {...WholesaleOrderController.pay.form(order.id)}>
                    {({ errors, processing }) => (
                        <div className="space-y-1">
                            <Button type="submit" disabled={processing}>
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CreditCard
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {processing
                                    ? t('orders.actions.paying')
                                    : t('orders.actions.pay')}
                            </Button>
                            <InputError message={errors.order} />
                        </div>
                    )}
                </Form>
            )}

            {can.cancel && (
                <div className="space-y-1">
                    <ConfirmDialog
                        trigger={
                            <Button type="button" variant="outline">
                                {t('orders.actions.cancel')}
                            </Button>
                        }
                        title={t('orders.actions.cancel')}
                        summary={t('orders.actions.cancel_confirm')}
                        confirmLabel={t('orders.actions.cancel')}
                        destructive
                        processing={cancelForm.processing}
                        onConfirm={() =>
                            cancelForm.post(
                                WholesaleOrderController.cancel.url(order.id),
                                { preserveScroll: true },
                            )
                        }
                    />
                    <InputError message={cancelErrors.order} />
                </div>
            )}
        </div>
    );
}

function Summary({ order }: Props) {
    const { t } = useTranslation();
    const { totals } = order;

    return (
        <SectionCard title={t('orders.sections.summary')}>
            <dl className="space-y-3 text-sm">
                <Row label={t('orders.summary.subtotal')}>
                    <MoneyAmount amount={totals.subtotal} />
                </Row>
                {isPositive(totals.discount) && (
                    <Row
                        label={
                            order.coupon_code
                                ? t('orders.summary.coupon', {
                                      code: order.coupon_code,
                                  })
                                : t('orders.summary.discount')
                        }
                    >
                        <MoneyAmount
                            amount={totals.discount}
                            direction="debit"
                        />
                    </Row>
                )}
                <Row label={t('orders.summary.delivery')}>
                    <MoneyAmount amount={totals.delivery} />
                </Row>
                {isPositive(totals.tax) && (
                    <Row label={t('orders.summary.tax')}>
                        <MoneyAmount amount={totals.tax} />
                    </Row>
                )}
                <div className="border-border flex items-baseline justify-between gap-3 border-t pt-3">
                    <dt className="font-medium">{t('orders.summary.total')}</dt>
                    <dd>
                        <MoneyAmount amount={totals.total} size="large" />
                    </dd>
                </div>
            </dl>
            {isPositive(totals.tax_included) && (
                <p className="text-muted-foreground mt-3 text-xs">
                    {t('orders.summary.tax_included', {
                        amount: totals.tax_included.formatted,
                    })}
                </p>
            )}
        </SectionCard>
    );
}

function PaymentCard({ order }: Props) {
    const { t } = useTranslation();

    return (
        <SectionCard title={t('orders.sections.payment')}>
            <dl className="space-y-3 text-sm">
                {order.payment_state && (
                    <Row label={t('orders.payment.status')}>
                        {t(`orders.payment_states.${order.payment_state}`)}
                    </Row>
                )}
                {order.payment?.gateway && (
                    <Row label={t('orders.payment.method')}>
                        {order.payment.gateway}
                    </Row>
                )}
                {order.payment && (
                    <Row label={t('orders.payment.reference')}>
                        <span className="font-mono text-xs">
                            {order.payment.reference}
                        </span>
                    </Row>
                )}
                <Row label={t('orders.payment.stock')}>
                    {t(`orders.stock_states.${order.stock.state}`)}
                </Row>
            </dl>

            <div className="border-border mt-4 border-t pt-4">
                {order.invoice ? (
                    <Button asChild variant="outline" className="w-full">
                        <Link href={invoiceShow(order.invoice.id)}>
                            <FileText className="size-4" aria-hidden="true" />
                            {t('orders.invoice.view', {
                                number: order.invoice.number,
                            })}
                        </Link>
                    </Button>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        {t('orders.invoice.none')}
                    </p>
                )}
            </div>
        </SectionCard>
    );
}

function Timeline({ order }: Props) {
    const { t, locale } = useTranslation();

    return (
        <SectionCard title={t('orders.sections.timeline')}>
            {order.timeline.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('orders.timeline.empty')}
                </p>
            ) : (
                <ol className="border-border space-y-4 border-l pl-4">
                    {order.timeline.map((entry, position) => (
                        <li key={`${entry.status}-${position}`}>
                            <p className="text-sm font-medium">
                                {t(`orders.statuses.${entry.status}`)}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                <time dateTime={entry.at}>
                                    {new Date(entry.at).toLocaleString(locale)}
                                </time>
                            </p>
                            {entry.note && (
                                <p className="mt-1 text-sm">{entry.note}</p>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

function AddressCard({
    title,
    address,
}: {
    title: string;
    address: CheckoutAddress | null;
}) {
    return (
        <SectionCard title={title}>
            {address && (
                <address className="space-y-0.5 text-sm not-italic">
                    <p className="font-medium">{address.contact_name}</p>
                    <p className="text-muted-foreground tabular-nums">
                        {address.contact_mobile}
                    </p>
                    <p>
                        {[address.line_1, address.line_2]
                            .filter(Boolean)
                            .join(', ')}
                    </p>
                    <p>
                        {[
                            address.area,
                            address.city,
                            address.district,
                            address.postcode,
                        ]
                            .filter(Boolean)
                            .join(', ')}
                    </p>
                </address>
            )}
        </SectionCard>
    );
}

function Row({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

WholesaleOrder.layout = {
    breadcrumbs: [
        {
            title: 'nav.wholesale_orders',
            href: index(),
        },
    ],
};
