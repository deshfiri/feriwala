import { Form, Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    CheckCircle2,
    Clock,
    CreditCard,
    FileText,
    MapPin,
    Receipt,
    Truck,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import WholesaleOrderController from '@/actions/App/Http/Controllers/Erp/WholesaleOrderController';
import ConfirmDialog from '@/components/forms/confirm-dialog';
import InputError from '@/components/input-error';
import LineThumb from '@/components/line-thumb';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { isPositive } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as invoiceShow } from '@/routes/subscription/invoices';
import { index } from '@/routes/wholesale/orders';
import type {
    OrderStatusValue,
    WholesaleOrderAbilities,
    WholesaleOrderDetail,
} from '@/types/orders';
import type { CheckoutAddress } from '@/types/wholesale';

type PaymentMethodOption = { name: string; label: string };

type Props = {
    order: WholesaleOrderDetail;
    payment_methods?: PaymentMethodOption[];
};

/** Once an order is in any of these, it has left the warehouse. */
const DELIVERED: OrderStatusValue[] = ['delivered', 'completed'];
const SHIPPED: OrderStatusValue[] = [
    ...DELIVERED,
    'ready_for_pickup',
    'courier_assigned',
    'shipped',
    'in_transit',
];

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
    payment_methods = [],
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

                <Hero order={order} title={title} placed={placed} />

                <OrderState order={order} />

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_23rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard
                            title={t('orders.sections.items')}
                            description={t('orders.lines.count', {
                                count: order.lines.length,
                            })}
                            contentClassName="p-0"
                        >
                            <ul className="divide-border divide-y">
                                {order.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex items-center gap-4 px-5 py-4"
                                    >
                                        <LineThumb
                                            name={line.name}
                                            quantity={line.quantity}
                                        />
                                        <div className="min-w-0 flex-1 space-y-0.5">
                                            <p className="leading-snug font-medium">
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
                                            {line.resale_amount && (
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'orders.lines.resale_amount',
                                                        {
                                                            amount: line
                                                                .resale_amount
                                                                .formatted,
                                                        },
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                        <MoneyAmount
                                            amount={line.total}
                                            className="shrink-0 font-semibold"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <div className="grid gap-6 md:grid-cols-2">
                            <AddressCard
                                title={t('orders.sections.billing')}
                                icon={Receipt}
                                address={order.addresses.billing}
                            />
                            <AddressCard
                                title={t('orders.sections.shipping')}
                                icon={Truck}
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

                    <div className="space-y-6 lg:sticky lg:top-20">
                        {(can.pay || can.cancel) && (
                            <OrderActions
                                order={order}
                                can={can}
                                methods={payment_methods}
                            />
                        )}
                        <Summary order={order} />
                        <PaymentCard order={order} />
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

/**
 * The order at a glance: what it is, where it stands, what it comes to.
 */
function Hero({
    order,
    title,
    placed,
}: {
    order: WholesaleOrderDetail;
    title: string;
    placed: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-start justify-between gap-x-8 gap-y-4 p-5 sm:p-6">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h1 className="text-2xl font-semibold tracking-tight break-all">
                            {title}
                        </h1>
                        <StatusPill
                            tone={order.status_tone}
                            label={t(`orders.statuses.${order.status}`)}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">{placed}</p>
                </div>

                <div className="sm:text-right">
                    <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                        {t('orders.hero.total')}
                    </p>
                    <MoneyAmount amount={order.totals.total} size="large" />
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        {t('orders.lines.count', { count: order.lines.length })}
                    </p>
                </div>
            </div>

            <ProgressTrack order={order} />
        </Card>
    );
}

/**
 * Placed → Paid → Shipped → Delivered.
 *
 * Reads only what the server already says (`status`, `paid_at`); an order that
 * was cancelled or put on hold has no happy path to show, so the track steps
 * aside for the state notice below.
 */
function ProgressTrack({ order }: { order: WholesaleOrderDetail }) {
    const { t } = useTranslation();

    if (order.status === 'cancelled' || order.status === 'on_hold') {
        return null;
    }

    const steps = [
        { key: 'placed', done: true },
        { key: 'paid', done: order.paid_at !== null },
        { key: 'shipped', done: SHIPPED.includes(order.status) },
        { key: 'delivered', done: DELIVERED.includes(order.status) },
    ];
    const current = steps.findIndex((step) => !step.done);

    return (
        <ol
            aria-label={t('orders.track.label')}
            className="bg-muted/50 grid grid-cols-4 gap-2 border-t px-5 py-4 sm:px-6"
        >
            {steps.map((step, position) => (
                <li
                    key={step.key}
                    aria-current={position === current ? 'step' : undefined}
                    className="flex flex-col gap-2"
                >
                    <span
                        className={cn(
                            'h-1 rounded-full',
                            step.done
                                ? 'bg-brand'
                                : position === current
                                  ? 'bg-brand/35'
                                  : 'bg-border',
                        )}
                    />
                    <span className="flex items-center gap-1.5 text-xs font-medium">
                        {step.done ? (
                            <Check
                                className="text-brand size-3.5"
                                aria-hidden="true"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2 rounded-full',
                                    position === current
                                        ? 'bg-brand/60'
                                        : 'bg-border',
                                )}
                            />
                        )}
                        <span
                            className={
                                step.done || position === current
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            }
                        >
                            {t(`orders.track.${step.key}`)}
                        </span>
                    </span>
                </li>
            ))}
        </ol>
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
 * Paying sends the order and the chosen method; the amount was fixed when it was
 * placed. Cancelling asks first, because it gives the held stock back.
 */
function OrderActions({
    order,
    can,
    methods,
}: Props & { can: WholesaleOrderAbilities; methods: PaymentMethodOption[] }) {
    const { t } = useTranslation();
    const currentGateway = order.payment?.gateway;
    const [method, setMethod] = useState(
        methods.find(
            (option) =>
                option.label === currentGateway ||
                option.name === currentGateway,
        )?.name ??
            methods[0]?.name ??
            '',
    );
    const cancelForm = useForm({});
    // The refusal comes back under the order, not under a field of this empty form.
    const cancelErrors: Partial<Record<string, string>> = cancelForm.errors;

    return (
        <SectionCard
            title={t(
                can.pay ? 'orders.pay_panel.title' : 'orders.sections.actions',
            )}
            description={can.pay ? t('orders.pay_panel.help') : undefined}
            className="border-brand-border"
        >
            <div className="space-y-4">
                {can.pay && (
                    <Form {...WholesaleOrderController.pay.form(order.id)}>
                        {({ errors, processing }) => (
                            <div className="space-y-4">
                                {methods.length > 1 && (
                                    <fieldset className="space-y-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            {t('orders.actions.method')}
                                        </legend>
                                        <p className="text-muted-foreground text-xs">
                                            {t('orders.actions.method_help')}
                                        </p>
                                        {methods.map((option) => (
                                            <label
                                                key={option.name}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors',
                                                    method === option.name
                                                        ? 'border-brand bg-brand-subtle'
                                                        : 'border-input hover:bg-muted',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="payment_method"
                                                    value={option.name}
                                                    checked={
                                                        method === option.name
                                                    }
                                                    onChange={() =>
                                                        setMethod(option.name)
                                                    }
                                                    className="accent-brand"
                                                />
                                                <span className="font-medium">
                                                    {option.label}
                                                </span>
                                            </label>
                                        ))}
                                        <InputError
                                            message={errors.payment_method}
                                        />
                                    </fieldset>
                                )}
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="w-full"
                                    disabled={processing}
                                >
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
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full"
                                >
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
                                    WholesaleOrderController.cancel.url(
                                        order.id,
                                    ),
                                    { preserveScroll: true },
                                )
                            }
                        />
                        <InputError message={cancelErrors.order} />
                    </div>
                )}
            </div>
        </SectionCard>
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
                <div className="bg-muted/60 -mx-5 -mb-1 flex items-baseline justify-between gap-3 border-t px-5 py-4">
                    <dt className="font-medium">{t('orders.summary.total')}</dt>
                    <dd>
                        <MoneyAmount amount={totals.total} size="large" />
                    </dd>
                </div>
            </dl>
            {isPositive(totals.tax_included) && (
                <p className="text-muted-foreground mt-4 text-xs">
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
                <ol className="space-y-5">
                    {order.timeline.map((entry, position) => (
                        <li
                            key={`${entry.status}-${position}`}
                            className="relative pl-6"
                        >
                            {position < order.timeline.length - 1 && (
                                <span
                                    aria-hidden="true"
                                    className="bg-border absolute top-4 bottom-[-1.25rem] left-[5px] w-px"
                                />
                            )}
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute top-1.5 left-0 size-[11px] rounded-full border-2',
                                    position === order.timeline.length - 1
                                        ? 'border-brand bg-brand'
                                        : 'border-border bg-background',
                                )}
                            />
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
    icon: Icon,
    address,
}: {
    title: string;
    icon: LucideIcon;
    address: CheckoutAddress | null;
}) {
    return (
        <SectionCard
            title={title}
            actions={
                <Icon
                    className="text-muted-foreground size-4"
                    aria-hidden="true"
                />
            }
        >
            {address ? (
                <address className="space-y-1 text-sm not-italic">
                    <p className="font-medium">{address.contact_name}</p>
                    <p className="text-muted-foreground tabular-nums">
                        {address.contact_mobile}
                    </p>
                    <p className="flex gap-2 pt-1">
                        <MapPin
                            className="text-muted-foreground mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            {[address.line_1, address.line_2]
                                .filter(Boolean)
                                .join(', ')}
                            <br />
                            {[
                                address.area,
                                address.city,
                                address.district,
                                address.postcode,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </span>
                    </p>
                </address>
            ) : null}
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
