import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { isZero, type Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import AllocationPanel, {
    type AllocationLine,
} from '@/pages/admin/orders/allocation-panel';
import FulfilmentCommitmentPanel, {
    type FulfilmentCommitment,
} from '@/pages/admin/orders/fulfilment-commitment-panel';
import OrderLifecyclePanel, {
    type OrderLifecycle,
} from '@/pages/admin/orders/order-lifecycle-panel';
import ShipmentsPanel, {
    type CourierProviderOption,
    type OrderShipment,
} from '@/pages/admin/orders/shipments-panel';
import { index } from '@/routes/admin/orders';

export type SupplierPayableSummary = {
    id: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    gross_amount: Money;
    reversed_amount: Money;
    net_amount: Money;
    delivered_at: string | null;
    payment_settled_at: string | null;
    eligible_at: string | null;
    settled_at: string | null;
    cancelled_at: string | null;
};

export type AdminOrderDetail = {
    id: string;
    reference: string;
    source: string;
    status: string;
    status_tone: StatusTone;
    lifecycle: OrderLifecycle;
    account: string;
    placed_by: string | null;
    /** The partner website an order came through, where one did (§18.5). */
    website: { id: string; name: string } | null;
    storefront_reference: string | null;
    website_customer: {
        id: string;
        mobile: string;
        is_guest: boolean;
    } | null;
    placed_at: string;
    paid_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    held_at: string | null;
    hold_reason: string | null;
    customer_note: string | null;
    intended_resale_channel: string | null;
    coupon_code: string | null;
    totals: { total: Money };
    lines: {
        id: string;
        name: string;
        sku: string;
        variant: string | null;
        quantity: number;
        unit_price: Money;
        total: Money;
        reservation: {
            reference: string;
            status: string;
            expires_at: string;
        } | null;
        /**
         * One row per source currently holding part of this line — several
         * when it is split across sources (Advanced Order Management batch,
         * Commit 3), one in the ordinary unsplit case, none before any
         * allocation has been made.
         */
        allocations: {
            id: string;
            source_type: string;
            source_type_label: string;
            /** Withheld (null) for a Supplier-sourced line without supplier_pricing.view. */
            source_label: string | null;
            quantity: number;
            /** Withheld (null) without the permission for this source's figures (D25). */
            unit_cost: Money | null;
            expected_margin: Money | null;
            allocated_at: string;
            fulfilment_commitment: FulfilmentCommitment | null;
            /** Withheld (null) without supplier_pricing.view, same as unit_cost (§8, P13-26). */
            payable: SupplierPayableSummary | null;
        }[];
        allocated_quantity: number;
        remaining_quantity: number;
        can_allocate: boolean;
    }[];
    payment: {
        reference: string;
        status: string;
        method: string;
        gateway: string | null;
        gateway_mode: string | null;
        expires_at: string | null;
        completed_at: string | null;
        reconciliation_reason: string | null;
    } | null;
    invoice: { number: string } | null;
    /**
     * A cash-on-delivery order's confirmation, or null for any other.
     *
     * Staff see how many guesses have been used, which is what answers "the
     * customer says their code does not work". The code itself is held hashed
     * and reaches no screen, no prop and no log (§6.2).
     */
    confirmation: {
        state: 'pending' | 'confirmed' | 'cancelled';
        expires_at: string | null;
        code_outstanding: boolean;
        resend_available_in: number;
        attempts_used: number;
        attempts_allowed: number;
        /** Whether the last code reached the SMS provider: sent, failed, or none tried. */
        code_delivery: 'sent' | 'failed' | null;
        sends_used: number;
        sends_allowed: number;
    } | null;
    history: {
        previous_status: string | null;
        new_status: string;
        at: string;
        source: string;
        actor: string | null;
        reason: string | null;
        internal_note: string | null;
    }[];
    /** Empty ([]) without manage_shipments (Advanced Order Management batch, Commit 5). */
    shipments: OrderShipment[];
    courier_providers: CourierProviderOption[];
};

type Props = {
    order: AdminOrderDetail;
    can: { cancel: boolean; manage_shipments: boolean };
};

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * One order as the staff who review it see it (§18.4, §18.5).
 *
 * Everything the buyer's page leaves out on purpose — why an order is held, why a
 * payment is under reconciliation, who moved it and their note — is shown here,
 * in words. The one action is cancelling an order nobody has paid for, with a
 * reason; resolving a held order is said plainly to be done elsewhere.
 */
export default function AdminOrder({ order, can }: Props) {
    const { t, locale } = useTranslation();
    const [cancelling, setCancelling] = useState(false);
    const [allocating, setAllocating] = useState<AllocationLine | null>(null);
    const at = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    return (
        <>
            <Head title={order.reference} />

            <PageContainer>
                <Link
                    href={index()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('orders.admin.back')}
                </Link>

                <PageHeader
                    title={order.reference}
                    description={order.account}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusPill
                                tone={order.status_tone}
                                label={t(`orders.statuses.${order.status}`)}
                            />
                            {can.cancel && (
                                <Button
                                    variant="outline"
                                    onClick={() => setCancelling(true)}
                                >
                                    {t('orders.admin.cancel')}
                                </Button>
                            )}
                        </div>
                    }
                />

                {order.hold_reason && (
                    <Notice
                        title={t('orders.admin.sections.hold')}
                        body={order.hold_reason}
                        footnote={t('orders.admin.resolution_pending')}
                    />
                )}

                {order.payment?.reconciliation_reason && (
                    <Notice
                        title={t('orders.admin.sections.reconciliation')}
                        body={order.payment.reconciliation_reason}
                    />
                )}

                <OrderLifecyclePanel
                    orderId={order.id}
                    lifecycle={order.lifecycle}
                />

                {can.manage_shipments && (
                    <ShipmentsPanel
                        orderId={order.id}
                        orderReference={order.reference}
                        shipments={order.shipments}
                        courierProviders={order.courier_providers}
                    />
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard
                            title={t('orders.admin.sections.lines')}
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
                                            </p>
                                            {line.reservation && (
                                                <p className="text-muted-foreground text-xs">
                                                    {t(
                                                        'orders.admin.fields.reservation',
                                                    )}
                                                    {': '}
                                                    <span className="font-mono">
                                                        {
                                                            line.reservation
                                                                .reference
                                                        }
                                                    </span>
                                                    {' · '}
                                                    {t(
                                                        `inventory.reservation_statuses.${line.reservation.status}`,
                                                    )}
                                                </p>
                                            )}
                                            {line.allocations.map(
                                                (allocation) => (
                                                    <div
                                                        key={allocation.id}
                                                        className="border-border mt-1 rounded-md border border-dashed p-2"
                                                    >
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <p className="text-muted-foreground text-xs">
                                                                {t(
                                                                    'orders.admin.allocation.current',
                                                                )}
                                                                {': '}
                                                                {allocation.source_label ??
                                                                    allocation.source_type_label}
                                                                {' · '}
                                                                {t(
                                                                    'orders.lines.quantity_each',
                                                                    {
                                                                        quantity:
                                                                            allocation.quantity,
                                                                        amount: line
                                                                            .unit_price
                                                                            .formatted,
                                                                    },
                                                                )}
                                                            </p>
                                                            {line.can_allocate && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    onClick={() =>
                                                                        setAllocating(
                                                                            {
                                                                                id: line.id,
                                                                                name: line.name,
                                                                                sku: line.sku,
                                                                                replacingAllocationId:
                                                                                    allocation.id,
                                                                                quantity:
                                                                                    allocation.quantity,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {t(
                                                                        'orders.admin.allocation.change_action',
                                                                    )}
                                                                </Button>
                                                            )}
                                                        </div>
                                                        {allocation.fulfilment_commitment && (
                                                            <FulfilmentCommitmentPanel
                                                                orderId={
                                                                    order.id
                                                                }
                                                                itemId={line.id}
                                                                commitment={
                                                                    allocation.fulfilment_commitment
                                                                }
                                                            />
                                                        )}
                                                        {allocation.payable && (
                                                            <div className="mt-2 flex flex-wrap items-center gap-2 border-t pt-2">
                                                                <StatusPill
                                                                    tone={
                                                                        allocation
                                                                            .payable
                                                                            .status_tone
                                                                    }
                                                                    label={
                                                                        allocation
                                                                            .payable
                                                                            .status_label
                                                                    }
                                                                />
                                                                <span className="text-muted-foreground text-xs">
                                                                    {t(
                                                                        'orders.admin.payable.net_amount',
                                                                    )}
                                                                    {': '}
                                                                </span>
                                                                <MoneyAmount
                                                                    amount={
                                                                        allocation
                                                                            .payable
                                                                            .net_amount
                                                                    }
                                                                    size="small"
                                                                />
                                                                {!isZero(
                                                                    allocation
                                                                        .payable
                                                                        .reversed_amount,
                                                                ) && (
                                                                    <span className="text-muted-foreground text-xs">
                                                                        (
                                                                        {t(
                                                                            'orders.admin.payable.reversed_amount',
                                                                        )}
                                                                        {': '}
                                                                        {
                                                                            allocation
                                                                                .payable
                                                                                .reversed_amount
                                                                                .formatted
                                                                        }
                                                                        )
                                                                    </span>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                        <div className="flex flex-col items-end gap-2">
                                            <MoneyAmount
                                                amount={line.total}
                                                className="font-semibold"
                                            />
                                            {line.can_allocate &&
                                                line.remaining_quantity > 0 && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setAllocating({
                                                                id: line.id,
                                                                name: line.name,
                                                                sku: line.sku,
                                                                quantity:
                                                                    line.remaining_quantity,
                                                            })
                                                        }
                                                    >
                                                        {line.allocations
                                                            .length > 0
                                                            ? t(
                                                                  'orders.admin.allocation.split_action',
                                                              )
                                                            : t(
                                                                  'orders.admin.allocation.action',
                                                              )}
                                                    </Button>
                                                )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <SectionCard title={t('orders.admin.sections.history')}>
                            <ol className="border-border space-y-4 border-l pl-4">
                                {order.history.map((entry, position) => (
                                    <li key={`${entry.new_status}-${position}`}>
                                        <p className="text-sm font-medium">
                                            {t(
                                                `orders.statuses.${entry.new_status}`,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            <time dateTime={entry.at}>
                                                {at(entry.at)}
                                            </time>
                                            {' · '}
                                            {t(
                                                `orders.admin.change_sources.${entry.source}`,
                                            )}
                                            {' · '}
                                            {entry.actor ??
                                                t(
                                                    'orders.admin.history.system',
                                                )}
                                        </p>
                                        {entry.reason && (
                                            <p className="mt-1 text-sm">
                                                {t(
                                                    'orders.admin.history.reason',
                                                    { reason: entry.reason },
                                                )}
                                            </p>
                                        )}
                                        {entry.internal_note && (
                                            <p className="mt-1 text-sm">
                                                {t(
                                                    'orders.admin.history.internal',
                                                    {
                                                        note: entry.internal_note,
                                                    },
                                                )}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>

                    <div className="space-y-6">
                        <SectionCard
                            title={t('orders.admin.sections.overview')}
                        >
                            <dl className="space-y-3 text-sm">
                                <Row label={t('orders.admin.fields.account')}>
                                    {order.account}
                                </Row>
                                {order.placed_by && (
                                    <Row
                                        label={t(
                                            'orders.admin.fields.placed_by',
                                        )}
                                    >
                                        {order.placed_by}
                                    </Row>
                                )}
                                <Row label={t('orders.admin.fields.placed_at')}>
                                    {at(order.placed_at)}
                                </Row>
                                <Row label={t('orders.admin.fields.source')}>
                                    {t(`orders.admin.sources.${order.source}`)}
                                </Row>
                                {order.website && (
                                    <Row
                                        label={t('orders.admin.fields.website')}
                                    >
                                        {order.website.name}
                                    </Row>
                                )}
                                {order.storefront_reference && (
                                    <Row
                                        label={t(
                                            'orders.admin.fields.storefront_reference',
                                        )}
                                    >
                                        <span className="font-mono">
                                            {order.storefront_reference}
                                        </span>
                                    </Row>
                                )}
                                {order.website_customer && (
                                    <Row
                                        label={t(
                                            'orders.admin.fields.website_customer',
                                        )}
                                    >
                                        <span className="font-mono">
                                            {order.website_customer.mobile}
                                        </span>
                                        {order.website_customer.is_guest && (
                                            <span className="text-muted-foreground ms-2 text-xs">
                                                {t('orders.admin.fields.guest')}
                                            </span>
                                        )}
                                    </Row>
                                )}
                                {order.intended_resale_channel && (
                                    <Row
                                        label={t('orders.admin.fields.resale')}
                                    >
                                        {t(
                                            `orders.resale.channels.${order.intended_resale_channel}`,
                                        )}
                                    </Row>
                                )}
                                {order.coupon_code && (
                                    <Row
                                        label={t('orders.admin.fields.coupon')}
                                    >
                                        {order.coupon_code}
                                    </Row>
                                )}
                                <Row label={t('orders.admin.fields.total')}>
                                    <MoneyAmount amount={order.totals.total} />
                                </Row>
                                {order.invoice && (
                                    <Row
                                        label={t('orders.admin.fields.invoice')}
                                    >
                                        {order.invoice.number}
                                    </Row>
                                )}
                            </dl>
                            {order.customer_note && (
                                <p className="border-border mt-4 border-t pt-3 text-sm whitespace-pre-line">
                                    {order.customer_note}
                                </p>
                            )}
                        </SectionCard>

                        {order.payment && (
                            <SectionCard
                                title={t('orders.admin.sections.payment')}
                            >
                                <dl className="space-y-3 text-sm">
                                    <Row
                                        label={t(
                                            'orders.admin.fields.payment_status',
                                        )}
                                    >
                                        {t(
                                            `orders.admin.payment_statuses.${order.payment.status}`,
                                        )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.fields.payment_method',
                                        )}
                                    >
                                        {t(
                                            `orders.admin.payment_methods.${order.payment.method}`,
                                        )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.fields.reference',
                                        )}
                                    >
                                        <span className="font-mono text-xs">
                                            {order.payment.reference}
                                        </span>
                                    </Row>
                                    {order.payment.gateway && (
                                        <Row
                                            label={t(
                                                'orders.admin.fields.gateway',
                                            )}
                                        >
                                            {order.payment.gateway}
                                            {order.payment.gateway_mode
                                                ? ` (${order.payment.gateway_mode})`
                                                : ''}
                                        </Row>
                                    )}
                                    <Row
                                        label={t(
                                            'orders.admin.fields.expires_at',
                                        )}
                                    >
                                        {at(order.payment.expires_at)}
                                    </Row>
                                    {order.payment.completed_at && (
                                        <Row
                                            label={t(
                                                'orders.admin.fields.completed_at',
                                            )}
                                        >
                                            {at(order.payment.completed_at)}
                                        </Row>
                                    )}
                                </dl>
                            </SectionCard>
                        )}

                        {order.confirmation && (
                            <SectionCard
                                title={t('orders.admin.sections.confirmation')}
                                description={t(
                                    'orders.admin.confirmation.hint',
                                )}
                            >
                                <dl className="space-y-3 text-sm">
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.state',
                                        )}
                                    >
                                        {t(
                                            `orders.admin.confirmation.states.${order.confirmation.state}`,
                                        )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.expires',
                                        )}
                                    >
                                        {at(order.confirmation.expires_at)}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.code',
                                        )}
                                    >
                                        {order.confirmation.code_outstanding
                                            ? t(
                                                  'orders.admin.confirmation.code_sent',
                                              )
                                            : t(
                                                  'orders.admin.confirmation.code_none',
                                              )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.attempts',
                                        )}
                                    >
                                        {t(
                                            'orders.admin.confirmation.attempts_value',
                                            {
                                                used: String(
                                                    order.confirmation
                                                        .attempts_used,
                                                ),
                                                allowed: String(
                                                    order.confirmation
                                                        .attempts_allowed,
                                                ),
                                            },
                                        )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.delivery',
                                        )}
                                    >
                                        {t(
                                            `orders.admin.confirmation.delivery_states.${order.confirmation.code_delivery ?? 'none'}`,
                                        )}
                                    </Row>
                                    <Row
                                        label={t(
                                            'orders.admin.confirmation.sends',
                                        )}
                                    >
                                        {t(
                                            'orders.admin.confirmation.sends_value',
                                            {
                                                used: String(
                                                    order.confirmation
                                                        .sends_used,
                                                ),
                                                allowed: String(
                                                    order.confirmation
                                                        .sends_allowed,
                                                ),
                                            },
                                        )}
                                    </Row>
                                    {order.confirmation.state === 'pending' && (
                                        <Row
                                            label={t(
                                                'orders.admin.confirmation.resend',
                                            )}
                                        >
                                            {order.confirmation
                                                .resend_available_in > 0
                                                ? t(
                                                      'orders.admin.confirmation.resend_in',
                                                      {
                                                          seconds: String(
                                                              order.confirmation
                                                                  .resend_available_in,
                                                          ),
                                                      },
                                                  )
                                                : t(
                                                      'orders.admin.confirmation.resend_now',
                                                  )}
                                        </Row>
                                    )}
                                </dl>
                            </SectionCard>
                        )}

                        {order.cancellation_reason && (
                            <SectionCard
                                title={t('orders.admin.sections.cancellation')}
                            >
                                <p className="text-sm">
                                    {order.cancellation_reason}
                                </p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {at(order.cancelled_at)}
                                </p>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </PageContainer>

            {can.cancel && cancelling && (
                <Dialog
                    open
                    onOpenChange={(next) => !next && setCancelling(false)}
                >
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>
                                {t('orders.admin.cancel_title', {
                                    reference: order.reference,
                                })}
                            </DialogTitle>
                            <DialogDescription>
                                {t('orders.admin.cancel_description')}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...OrderController.cancel.form(order.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setCancelling(false)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="cancel-reason">
                                            {t('orders.admin.reason')}
                                        </Label>
                                        <textarea
                                            id="cancel-reason"
                                            name="reason"
                                            rows={3}
                                            minLength={10}
                                            maxLength={1000}
                                            required
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t('orders.admin.reason_help')}
                                        </p>
                                        <InputError message={errors.reason} />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setCancelling(false)}
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('orders.admin.cancel')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}

            <AllocationPanel
                open={allocating !== null}
                onOpenChange={(next) => {
                    if (!next) setAllocating(null);
                }}
                orderId={order.id}
                line={allocating}
            />
        </>
    );
}

function Notice({
    title,
    body,
    footnote,
}: {
    title: string;
    body: string;
    footnote?: string;
}) {
    return (
        <div
            role="status"
            className="border-warning bg-warning-subtle flex gap-3 rounded-xl border p-4"
        >
            <AlertTriangle
                className="mt-0.5 size-5 shrink-0"
                aria-hidden="true"
            />
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                <p className="text-sm">{body}</p>
                {footnote && (
                    <p className="text-muted-foreground text-xs">{footnote}</p>
                )}
            </div>
        </div>
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

AdminOrder.layout = {
    breadcrumbs: [
        {
            title: 'nav.orders',
            href: index(),
        },
    ],
};
