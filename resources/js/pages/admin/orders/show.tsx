import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import OrderController from '@/actions/App/Http/Controllers/Admin/OrderController';
import AdminStorefrontProfitPanel, {
    type AdminStorefrontProfit,
} from '@/components/admin-storefront-profit-panel';
import InputError from '@/components/input-error';
import ExchangeOriginNotice from '@/components/cases/exchange-origin';
import MoneyAmount from '@/components/money-amount';
import OrderCustomerCard from '@/components/order-customer-card';
import type { OrderCustomerDetails } from '@/components/order-customer-card';
import type { ExchangeOrigin } from '@/types/cases';
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
import OrderActivity from '@/pages/admin/orders/order-activity';
import type { OrderLifecycle } from '@/pages/admin/orders/order-lifecycle';
import OrderProgress from '@/pages/admin/orders/order-progress';
import OrderStatusUpdate from '@/pages/admin/orders/order-status-update';
import StoreOrderStepsPanel from '@/pages/admin/orders/store-order-steps';
import type { StoreOrderSteps } from '@/pages/admin/orders/store-order-steps';
import ShipmentsPanel, {
    type CourierProviderOption,
    type OrderShipment,
} from '@/pages/admin/orders/shipments-panel';
import { index } from '@/routes/admin/orders';
import ReasonTextarea from '@/components/forms/reason-textarea';
import EditableOrderDetails, {
    type OrderRevisionPanel,
} from '@/pages/admin/orders/editable-order-details';
import RevisionHistory from '@/pages/admin/orders/revision-history';
import AmendOrderDialog from '@/pages/admin/orders/amend-order-dialog';

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
    account_type: 'conditional' | 'non_conditional';
    /** A Storefront order's Partner profit, and whether staff may confirm its COD; null for any other order. */
    storefront_profit?: AdminStorefrontProfit | null;
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
    /** Who the order is for and where it goes, as the server reads it. */
    customer_details: OrderCustomerDetails;
    intended_resale_channel: string | null;
    coupon_code: string | null;
    /** The server's own breakdown; optional lines are null when nothing is on them. */
    totals: {
        subtotal: Money;
        discount: Money | null;
        delivery: Money;
        /** A delivery-charge rule set it to zero on purpose. */
        delivery_free: boolean;
        /** The area the frozen charge was priced for, already translated. */
        delivery_area: string | null;
        tax: Money | null;
        cod_fee: Money | null;
        total: Money;
    };
    lines: {
        id: string;
        name: string;
        /** The catalogue product's page; null when the viewer may not open it or it was deleted. */
        product_url: string | null;
        sku: string;
        variant: string | null;
        quantity: number;
        unit_price: Money;
        total: Money;
        /** A Non-Conditional account's declared resale/COD amount (D-new). */
        resale_amount: Money | null;
        /**
         * This line's reseller-earning settlement (D-new): two independent
         * facts, `Delivered` and the COD cash actually collected, neither of
         * which alone credits anything. Null until `Delivered` is reached
         * once.
         */
        proceeds: {
            resale_amount: Money;
            recovered_amount: Money;
            delivered_at: string | null;
            cod_collected_at: string | null;
            cod_amount_collected: Money | null;
            eligible_at: string | null;
            reseller_earning: Money | null;
            flagged_for_review: boolean;
        } | null;
        can_record_cod_collection: boolean;
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
    can: {
        cancel: boolean;
        manage_shipments: boolean;
        record_cod_collection: boolean;
    };
    exchange_origin?: ExchangeOrigin | null;
    /** A storefront order's steps (D34), shown on this page; null for every other order. */
    store_order?: StoreOrderSteps | null;
    /** Correcting the order through revisions, and its revision history (D38). */
    revision?: OrderRevisionPanel | null;
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
export default function AdminOrder({
    order,
    can,
    exchange_origin,
    store_order = null,
    revision = null,
}: Props) {
    const { t, locale } = useTranslation();
    const [cancelling, setCancelling] = useState(false);
    const mayRevise =
        revision !== null &&
        (revision.can.edit_customer || revision.can.edit_address);
    // The full Order Details editor (D38, amended 2026-11-26): only while the
    // order may still be revised, for staff who may change any part of it.
    const mayAmend =
        revision !== null &&
        revision.blocker === null &&
        (mayRevise ||
            revision.can.revise_items === true ||
            revision.can.revise_financials === true);
    // A storefront order is cancelled as a store order: before it leaves the
    // warehouse, paid or not. Every other order keeps the unpaid-order cancel.
    const mayCancel = store_order ? store_order.can.cancel : can.cancel;
    const [allocating, setAllocating] = useState<AllocationLine | null>(null);
    const [recordingCod, setRecordingCod] = useState<{
        id: string;
        name: string;
        resaleAmount: Money | null;
    } | null>(null);
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
                            {mayAmend && revision?.amend && (
                                <AmendOrderDialog
                                    orderId={order.id}
                                    revision={revision}
                                    amend={revision.amend}
                                />
                            )}
                            {mayCancel && (
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

                <ExchangeOriginNotice origin={exchange_origin} />

                {mayRevise && revision?.blocker_message && (
                    <Notice
                        title={t('orders.revision.blocked_title')}
                        body={revision.blocker_message}
                    />
                )}

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

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <OrderProgress lifecycle={order.lifecycle} />

                        {/* A storefront order moves by its named steps; every other order by its tracks. */}
                        {store_order ? (
                            <StoreOrderStepsPanel steps={store_order} />
                        ) : (
                            <OrderStatusUpdate
                                orderId={order.id}
                                lifecycle={order.lifecycle}
                                shipments={order.shipments}
                                canManageShipments={can.manage_shipments}
                            />
                        )}

                        {can.manage_shipments && (
                            <ShipmentsPanel
                                orderId={order.id}
                                orderReference={order.reference}
                                shipments={order.shipments}
                                courierProviders={order.courier_providers}
                            />
                        )}

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
                                                {line.product_url ? (
                                                    <Link
                                                        href={line.product_url}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {line.name}
                                                    </Link>
                                                ) : (
                                                    line.name
                                                )}
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
                                            {order.account_type ===
                                                'non_conditional' &&
                                                line.resale_amount && (
                                                    <ProceedsBlock
                                                        line={line}
                                                        onRecordCollection={() =>
                                                            setRecordingCod({
                                                                id: line.id,
                                                                name: line.name,
                                                                resaleAmount:
                                                                    line.resale_amount,
                                                            })
                                                        }
                                                    />
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

                        <OrderActivity
                            history={order.history}
                            lifecycle={order.lifecycle}
                        />

                        {revision && (
                            <RevisionHistory history={revision.history} />
                        )}
                    </div>

                    <div className="space-y-6">
                        {/* Each value editable in place, as a revision (D38), for staff who may. */}
                        {revision && mayRevise ? (
                            <EditableOrderDetails
                                orderId={order.id}
                                revision={revision}
                            />
                        ) : (
                            <OrderCustomerCard
                                customer={order.customer_details}
                            />
                        )}

                        {order.storefront_profit && (
                            <AdminStorefrontProfitPanel
                                orderId={order.id}
                                data={order.storefront_profit}
                            />
                        )}

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
                                <Row label={t('orders.summary.subtotal')}>
                                    <MoneyAmount
                                        amount={order.totals.subtotal}
                                    />
                                </Row>
                                {order.totals.discount && (
                                    <Row label={t('orders.summary.discount')}>
                                        <MoneyAmount
                                            amount={order.totals.discount}
                                            direction="debit"
                                        />
                                    </Row>
                                )}
                                <Row
                                    label={
                                        order.totals.delivery_area
                                            ? `${t('orders.summary.delivery')} (${order.totals.delivery_area})`
                                            : t('orders.summary.delivery')
                                    }
                                >
                                    {order.totals.delivery_free ? (
                                        t('delivery_charges.free')
                                    ) : (
                                        <MoneyAmount
                                            amount={order.totals.delivery}
                                        />
                                    )}
                                </Row>
                                {order.totals.tax && (
                                    <Row label={t('orders.summary.tax')}>
                                        <MoneyAmount
                                            amount={order.totals.tax}
                                        />
                                    </Row>
                                )}
                                {order.totals.cod_fee && (
                                    <Row label={t('orders.summary.cod_fee')}>
                                        <MoneyAmount
                                            amount={order.totals.cod_fee}
                                        />
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

            {mayCancel && cancelling && (
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
                            {...(store_order
                                ? {
                                      action: store_order.urls.cancel,
                                      method: 'post' as const,
                                  }
                                : OrderController.cancel.form(order.id))}
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
                                        <ReasonTextarea
                                            context="order"
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

            {can.record_cod_collection && recordingCod && (
                <Dialog
                    open
                    onOpenChange={(next) => !next && setRecordingCod(null)}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                {t('orders.admin.cod_collection.title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t('orders.admin.cod_collection.description', {
                                    name: recordingCod.name,
                                })}
                            </DialogDescription>
                        </DialogHeader>

                        <Form
                            {...OrderController.recordCodCollection.form({
                                order: order.id,
                                item: recordingCod.id,
                            })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRecordingCod(null)}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="cod-amount-collected">
                                            {t(
                                                'orders.admin.cod_collection.amount_collected',
                                            )}
                                        </Label>
                                        <input
                                            id="cod-amount-collected"
                                            name="amount_collected"
                                            type="text"
                                            inputMode="decimal"
                                            pattern="^\d+(\.\d{1,2})?$"
                                            required
                                            defaultValue={
                                                recordingCod.resaleAmount
                                                    ?.amount
                                            }
                                            className={controlClass}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'orders.admin.cod_collection.amount_help',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.amount_collected}
                                        />
                                    </div>

                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                setRecordingCod(null)
                                            }
                                        >
                                            {t('common.actions.cancel')}
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t(
                                                'orders.admin.cod_collection.submit',
                                            )}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function ProceedsBlock({
    line,
    onRecordCollection,
}: {
    line: AdminOrderDetail['lines'][number];
    onRecordCollection: () => void;
}) {
    const { t } = useTranslation();
    const proceeds = line.proceeds;

    return (
        <div className="border-border mt-1 rounded-md border border-dashed p-2">
            <p className="text-muted-foreground text-xs">
                {t('orders.admin.proceeds.resale_amount')}
                {': '}
                <span className="text-foreground font-medium">
                    {line.resale_amount?.formatted}
                </span>
            </p>

            {proceeds === null ? (
                <p className="text-muted-foreground mt-1 text-xs">
                    {t('orders.admin.proceeds.awaiting_delivery')}
                </p>
            ) : (
                <>
                    <p className="text-muted-foreground mt-1 text-xs">
                        {t('orders.admin.proceeds.recovered_amount')}
                        {': '}
                        {proceeds.recovered_amount.formatted}
                    </p>

                    {proceeds.cod_collected_at !== null && (
                        <p className="text-muted-foreground mt-1 text-xs">
                            {t('orders.admin.proceeds.collected_amount')}
                            {': '}
                            {proceeds.cod_amount_collected?.formatted}
                        </p>
                    )}

                    {proceeds.eligible_at !== null &&
                        proceeds.reseller_earning && (
                            <div className="mt-2 flex flex-wrap items-center gap-2 border-t pt-2">
                                <StatusPill
                                    tone={
                                        proceeds.flagged_for_review
                                            ? 'warning'
                                            : 'success'
                                    }
                                    label={
                                        proceeds.flagged_for_review
                                            ? t('orders.admin.proceeds.flagged')
                                            : t('orders.admin.proceeds.settled')
                                    }
                                />
                                <span className="text-muted-foreground text-xs">
                                    {t('orders.admin.proceeds.earning')}
                                    {': '}
                                </span>
                                <MoneyAmount
                                    amount={proceeds.reseller_earning}
                                    size="small"
                                />
                            </div>
                        )}
                </>
            )}

            {/*
                Independent of whether `Delivered` has been reached yet — the
                two facts can arrive in either order (D-new) — so this is
                offered whenever it is not yet recorded, not only once a
                settlement row exists.
            */}
            {!proceeds?.cod_collected_at && line.can_record_cod_collection && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="mt-1"
                    onClick={onRecordCollection}
                >
                    {t('orders.admin.cod_collection.action')}
                </Button>
            )}
        </div>
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
