import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CreditCard,
    MapPin,
    TicketPercent,
} from 'lucide-react';
import { useState } from 'react';
import WholesaleCheckoutController from '@/actions/App/Http/Controllers/Erp/WholesaleCheckoutController';
import WholesaleOrderController from '@/actions/App/Http/Controllers/Erp/WholesaleOrderController';
import InputError from '@/components/input-error';
import LineThumb from '@/components/line-thumb';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { isPositive } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as addressesIndex } from '@/routes/addresses';
import { show as cartShow } from '@/routes/wholesale/cart';
import { show } from '@/routes/wholesale/checkout';
import { show as orderShow } from '@/routes/wholesale/orders';
import type { CheckoutSummary } from '@/types/wholesale';
import GatewayFxNote from '@/components/gateway-fx-note';

type Props = {
    checkout: CheckoutSummary;
};

/**
 * ERP wholesale checkout (§14).
 *
 * A review of what the server priced on this request. The page never totals,
 * discounts, taxes or charges delivery on anything: a coupon is sent as a code, an
 * address as the address, and every figure shown comes back from the server.
 */
export default function WholesaleCheckout({ checkout }: Props) {
    const { t } = useTranslation();
    const coupon = checkout.coupon;

    return (
        <>
            <Head title={t('wholesale.checkout.title')} />

            <PageContainer>
                <Link
                    href={cartShow()}
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t('wholesale.checkout.back_to_cart')}
                </Link>

                <PageHeader
                    title={t('wholesale.checkout.title')}
                    description={t('wholesale.checkout.description')}
                />

                <CheckoutSteps checkout={checkout} />

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_23rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard
                            title={t('wholesale.checkout.items')}
                            contentClassName="p-0"
                        >
                            <ul className="divide-border divide-y">
                                {checkout.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex items-center gap-4 px-5 py-4"
                                    >
                                        <LineThumb
                                            name={line.name}
                                            quantity={line.quantity}
                                        />
                                        <div className="min-w-0 flex-1">
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
                                                    'wholesale.checkout.quantity_each',
                                                    {
                                                        quantity: line.quantity,
                                                        amount: line.unit_price
                                                            .formatted,
                                                    },
                                                )}
                                            </p>
                                        </div>
                                        <MoneyAmount
                                            amount={line.line_total}
                                            className="shrink-0 font-semibold"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <AddressPicker checkout={checkout} />

                        <SectionCard title={t('wholesale.checkout.coupon')}>
                            {coupon && (
                                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                                    <div className="space-y-1">
                                        <StatusPill
                                            tone={
                                                coupon.accepted
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                            label={coupon.entered}
                                        />
                                        <p className="text-sm">
                                            {coupon.accepted && coupon.discount
                                                ? t(
                                                      'wholesale.checkout.coupon_accepted',
                                                      {
                                                          code: coupon.entered,
                                                          amount: coupon
                                                              .discount
                                                              .formatted,
                                                      },
                                                  )
                                                : t(
                                                      'wholesale.checkout.coupon_no_longer_applies',
                                                      {
                                                          code: coupon.entered,
                                                          reason:
                                                              coupon.reason ??
                                                              '',
                                                      },
                                                  )}
                                        </p>
                                    </div>
                                    <Form
                                        {...WholesaleCheckoutController.removeCoupon.form()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {t(
                                                    'wholesale.checkout.remove_coupon',
                                                )}
                                            </Button>
                                        )}
                                    </Form>
                                </div>
                            )}

                            <Form
                                {...WholesaleCheckoutController.applyCoupon.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="flex flex-wrap items-start gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid min-w-0 flex-1 gap-2 sm:max-w-xs">
                                            <Label htmlFor="checkout-coupon">
                                                {t(
                                                    'wholesale.checkout.coupon_code',
                                                )}
                                            </Label>
                                            <Input
                                                id="checkout-coupon"
                                                name="code"
                                                autoComplete="off"
                                                maxLength={64}
                                                required
                                            />
                                            <InputError
                                                message={
                                                    errors.code ?? errors.cart
                                                }
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="sm:mt-6"
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <TicketPercent
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {t('wholesale.checkout.apply')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </SectionCard>
                    </div>

                    <div className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                        <SectionCard title={t('wholesale.checkout.summary')}>
                            <dl className="space-y-3 text-sm">
                                <SummaryRow
                                    label={t('wholesale.checkout.subtotal')}
                                >
                                    <MoneyAmount amount={checkout.subtotal} />
                                </SummaryRow>
                                {isPositive(checkout.discount) && (
                                    <SummaryRow
                                        label={t('wholesale.checkout.discount')}
                                    >
                                        <MoneyAmount
                                            amount={checkout.discount}
                                            direction="debit"
                                        />
                                    </SummaryRow>
                                )}
                                <SummaryRow
                                    label={t('wholesale.checkout.delivery')}
                                >
                                    <MoneyAmount amount={checkout.delivery} />
                                </SummaryRow>
                                {isPositive(checkout.tax_added) && (
                                    <SummaryRow
                                        label={t('wholesale.checkout.tax')}
                                    >
                                        <MoneyAmount
                                            amount={checkout.tax_added}
                                        />
                                    </SummaryRow>
                                )}
                                <div className="bg-muted/60 -mx-5 flex items-baseline justify-between gap-3 border-t px-5 py-4">
                                    <dt className="font-medium">
                                        {t('wholesale.checkout.total')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={checkout.total}
                                            size="large"
                                        />
                                    </dd>
                                </div>

                                {checkout.non_conditional && (
                                    <div className="border-border flex items-baseline justify-between gap-3 border-t pt-3">
                                        <dt className="font-medium">
                                            {t('wholesale.checkout.due_now')}
                                        </dt>
                                        <dd>
                                            <MoneyAmount
                                                amount={checkout.amount_due_now}
                                                size="large"
                                            />
                                        </dd>
                                    </div>
                                )}
                            </dl>

                            {checkout.non_conditional && (
                                <p className="text-muted-foreground mt-3 text-xs">
                                    {t('wholesale.checkout.due_now_help')}
                                </p>
                            )}

                            {/*
                                Tax per rate (D19). Inclusive tax appears here and
                                nowhere else: it is already inside the prices above,
                                and a second line would read as a second charge.
                            */}
                            {checkout.tax.length > 0 && (
                                <div className="border-border mt-4 border-t pt-3">
                                    <p className="text-muted-foreground mb-2 text-xs font-medium">
                                        {t('wholesale.checkout.tax_breakdown')}
                                    </p>
                                    <dl className="space-y-1.5">
                                        {checkout.tax.map((charge) => (
                                            <div
                                                key={`${charge.code}-${charge.rate_basis_points}-${charge.mode}`}
                                                className="text-muted-foreground flex items-start justify-between gap-3 text-xs"
                                            >
                                                <dt>
                                                    {t(
                                                        'billing.tax.checkout_line',
                                                        {
                                                            label: charge.label,
                                                            net: charge.net
                                                                .formatted,
                                                        },
                                                    )}
                                                    {charge.mode ===
                                                        'inclusive' &&
                                                        ` · ${t('billing.tax.checkout_included', { amount: charge.tax.formatted })}`}
                                                </dt>
                                                <dd className="tabular-nums">
                                                    {charge.tax.formatted}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>
                            )}
                        </SectionCard>

                        {!checkout.ready_to_confirm && (
                            <p
                                role="status"
                                className="border-warning bg-warning-subtle flex gap-2 rounded-lg border p-3 text-sm"
                            >
                                <MapPin
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {t('wholesale.checkout.addresses_needed')}
                            </p>
                        )}

                        {checkout.pending_order && (
                            <PendingOrderNotice
                                order={checkout.pending_order}
                            />
                        )}

                        <ConfirmationPanel checkout={checkout} />

                        {checkout.confirmation?.status === 'confirmed' &&
                            !checkout.pending_order && (
                                <PayPanel checkout={checkout} />
                            )}
                    </div>
                </div>
            </PageContainer>
        </>
    );
}

/**
 * Address → Confirm → Pay, read from what the server already says: an address
 * is chosen when the checkout is ready to confirm, confirmed when a standing
 * confirmation exists, and payment is the step left.
 */
function CheckoutSteps({ checkout }: { checkout: CheckoutSummary }) {
    const { t } = useTranslation();
    const confirmed = checkout.confirmation?.status === 'confirmed';
    const steps = [
        { key: 'address', done: checkout.ready_to_confirm },
        { key: 'confirm', done: confirmed },
        { key: 'pay', done: false },
    ];
    const current = steps.findIndex((step) => !step.done);

    return (
        <ol
            aria-label={t('wholesale.checkout.steps.label')}
            className="grid grid-cols-3 gap-2"
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
                    <span className="flex items-center gap-1.5 text-sm font-medium">
                        {step.done ? (
                            <Check
                                className="text-brand size-4"
                                aria-hidden="true"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground text-xs tabular-nums"
                            >
                                {position + 1}
                            </span>
                        )}
                        <span
                            className={
                                step.done || position === current
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            }
                        >
                            {t(`wholesale.checkout.steps.${step.key}`)}
                        </span>
                    </span>
                </li>
            ))}
        </ol>
    );
}
/**
 * Payment method and checkout confirmation (P4-8).
 *
 * Sends the chosen method and the fingerprint of the summary on this page, never
 * a figure. If the order has changed since, the server refuses and the page shows
 * the new summary to confirm instead.
 */
function ConfirmationPanel({ checkout }: { checkout: CheckoutSummary }) {
    const { t, locale } = useTranslation();
    const confirmation = checkout.confirmation;
    const methods = checkout.payment_methods;
    const previous = confirmation?.payment_method.name;
    const [method, setMethod] = useState(
        methods.some((option) => option.name === previous)
            ? (previous ?? '')
            : (methods[0]?.name ?? ''),
    );

    if (confirmation?.status === 'confirmed') {
        return (
            <SectionCard title={t('wholesale.checkout.confirmation.title')}>
                <div className="space-y-3">
                    <StatusPill
                        tone="success"
                        label={t('wholesale.checkout.confirmation.confirmed')}
                    />
                    <p className="text-sm">
                        {t('wholesale.checkout.confirmation.confirmed_detail', {
                            at: new Date(
                                confirmation.confirmed_at,
                            ).toLocaleString(locale),
                            amount: confirmation.total.formatted,
                            method: confirmation.payment_method.label,
                        })}
                    </p>
                    <Form
                        {...WholesaleCheckoutController.withdrawConfirmation.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('wholesale.checkout.confirmation.change')}
                            </Button>
                        )}
                    </Form>
                </div>
            </SectionCard>
        );
    }

    return (
        <SectionCard
            title={t('wholesale.checkout.confirmation.title')}
            description={t('wholesale.checkout.confirmation.help')}
        >
            {confirmation?.status === 'stale' && (
                <div
                    role="status"
                    className="border-warning bg-warning-subtle mb-4 space-y-2 rounded-lg border p-3 text-sm"
                >
                    <StatusPill
                        tone="warning"
                        label={t('wholesale.checkout.confirmation.stale')}
                    />
                    <p>
                        {t('wholesale.checkout.confirmation.stale_detail', {
                            amount: confirmation.total.formatted,
                        })}
                    </p>
                </div>
            )}

            <Form
                {...WholesaleCheckoutController.confirm.form()}
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="fingerprint"
                            value={checkout.fingerprint}
                        />

                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">
                                {t('wholesale.checkout.payment.legend')}
                            </legend>

                            {methods.map((option) => (
                                <label
                                    key={option.name}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm',
                                        method === option.name
                                            ? 'border-brand bg-brand-subtle'
                                            : 'border-input hover:bg-muted',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        value={option.name}
                                        checked={method === option.name}
                                        onChange={() => setMethod(option.name)}
                                        className="accent-brand"
                                    />
                                    <span className="min-w-0">
                                        <span className="font-medium">
                                            {option.label}
                                        </span>
                                        <GatewayFxNote fx={option.fx} />
                                    </span>
                                </label>
                            ))}

                            {methods.length === 0 && (
                                <p className="text-danger text-sm">
                                    {t('wholesale.checkout.payment.none')}
                                </p>
                            )}
                        </fieldset>

                        <InputError
                            message={
                                errors.payment_method ??
                                errors.fingerprint ??
                                errors.addresses ??
                                errors.cart
                            }
                        />

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={
                                processing ||
                                methods.length === 0 ||
                                !checkout.ready_to_confirm
                            }
                        >
                            {processing && <Spinner />}
                            {t('wholesale.checkout.confirmation.confirm')}
                        </Button>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Pay for the confirmed summary, which places the order (P4-9, P4-14).
 *
 * Sends the fingerprint of the summary on this page, an optional note and an
 * optional resale channel — never a figure. The server prices the order again,
 * reserves its stock and sends the person to the gateway; if anything changed, it
 * refuses and says why here.
 */
function PayPanel({ checkout }: { checkout: CheckoutSummary }) {
    const { t } = useTranslation();
    // What is actually charged now — the delivery charge alone for a
    // Non-Conditional account, the confirmed total otherwise (D-new).
    const total = checkout.non_conditional
        ? checkout.amount_due_now.formatted
        : (checkout.confirmation?.total.formatted ?? '');
    const gateway = checkout.confirmation?.payment_method.label ?? '';

    return (
        <SectionCard title={t('orders.pay.title')}>
            <Form
                {...WholesaleOrderController.store.form()}
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="fingerprint"
                            value={checkout.fingerprint}
                        />

                        <p className="text-sm">
                            {t('orders.pay.body', { total, gateway })}
                        </p>

                        {checkout.non_conditional && (
                            <p className="text-muted-foreground text-xs">
                                {t('orders.pay.non_conditional_note')}
                            </p>
                        )}

                        <div className="grid gap-1.5">
                            <Label htmlFor="order-note">
                                {t('orders.pay.note')}
                            </Label>
                            <textarea
                                id="order-note"
                                name="customer_note"
                                rows={3}
                                maxLength={1000}
                                className={controlClass}
                            />
                            <InputError message={errors.customer_note} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="order-resale-channel">
                                {t('orders.resale.label')}
                            </Label>
                            <select
                                id="order-resale-channel"
                                name="intended_resale_channel"
                                defaultValue=""
                                className={controlClass}
                            >
                                <option value="">
                                    {t('orders.resale.none')}
                                </option>
                                {checkout.resale_channels.map((channel) => (
                                    <option key={channel} value={channel}>
                                        {t(`orders.resale.channels.${channel}`)}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                {t('orders.resale.help')}
                            </p>
                            <InputError
                                message={errors.intended_resale_channel}
                            />
                        </div>

                        <InputError
                            message={
                                errors.stock ??
                                errors.fingerprint ??
                                errors.confirmation ??
                                errors.coupon ??
                                errors.order ??
                                errors.cart ??
                                errors.addresses ??
                                errors.payment_method
                            }
                        />

                        <Button
                            type="submit"
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
                                ? t('orders.pay.submitting')
                                : t('orders.pay.submit', { total })}
                        </Button>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}

/**
 * An order from this cart is already waiting for payment: the way to it, instead
 * of a second way to pay.
 */
function PendingOrderNotice({
    order,
}: {
    order: NonNullable<CheckoutSummary['pending_order']>;
}) {
    const { t } = useTranslation();

    return (
        <div
            role="status"
            className="border-warning bg-warning-subtle space-y-2 rounded-lg border p-3 text-sm"
        >
            <p className="font-medium">{t('orders.pay.pending_title')}</p>
            <p>
                {t('orders.pay.pending_body', { reference: order.reference })}
            </p>
            <Link
                href={orderShow(order.id)}
                className="font-medium underline underline-offset-4"
            >
                {t('orders.pay.pending_link', { reference: order.reference })}
            </Link>
        </div>
    );
}

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/**
 * The one address this checkout uses, chosen from the Partner's saved addresses.
 *
 * Addresses are kept in the Addresses tab and nowhere else; choosing one here
 * sets both billing and delivery. The page sends the chosen address's identifier
 * and nothing else.
 */
function AddressPicker({ checkout }: { checkout: CheckoutSummary }) {
    const { t } = useTranslation();
    const book = checkout.address_book;
    const [chosen, setChosen] = useState(
        checkout.selected_address_id ??
            book.find((entry) => entry.is_default)?.id ??
            book[0]?.id ??
            '',
    );

    return (
        <SectionCard
            title={t('wholesale.checkout.address.choose')}
            description={t('wholesale.checkout.address.choose_help')}
            actions={
                <Button asChild variant="ghost" size="sm">
                    <Link href={addressesIndex()}>
                        {t('wholesale.checkout.address.manage')}
                    </Link>
                </Button>
            }
        >
            {book.length === 0 ? (
                <p className="text-muted-foreground text-sm">
                    {t('wholesale.checkout.address.none_saved')}
                </p>
            ) : (
                <Form
                    {...WholesaleCheckoutController.useAddress.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <fieldset className="space-y-2">
                                <legend className="sr-only">
                                    {t('wholesale.checkout.address.choose')}
                                </legend>
                                {book.map((entry) => (
                                    <label
                                        key={entry.id}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm',
                                            chosen === entry.id
                                                ? 'border-brand bg-brand-subtle'
                                                : 'border-input hover:bg-muted',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="address"
                                            value={entry.id}
                                            checked={chosen === entry.id}
                                            onChange={() => setChosen(entry.id)}
                                            className="accent-brand mt-1"
                                        />
                                        <span className="min-w-0 space-y-0.5">
                                            <span className="block font-medium">
                                                {entry.contact_name}
                                                {entry.is_default && (
                                                    <span className="text-muted-foreground ml-2 text-xs font-normal">
                                                        {t(
                                                            'wholesale.checkout.address.default',
                                                        )}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-muted-foreground block tabular-nums">
                                                {entry.contact_mobile}
                                            </span>
                                            <span className="block">
                                                {entry.line}
                                            </span>
                                            <span className="block">
                                                {entry.place}
                                            </span>
                                            {!entry.has_postcode && (
                                                <span className="text-warning block text-xs">
                                                    {t(
                                                        'wholesale.checkout.address.postcode_missing',
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </fieldset>

                            <InputError message={errors.address} />

                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    chosen === '' ||
                                    chosen === checkout.selected_address_id
                                }
                            >
                                {processing && <Spinner />}
                                {t('wholesale.checkout.address.use')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </SectionCard>
    );
}
WholesaleCheckout.layout = {
    breadcrumbs: [
        {
            title: 'wholesale.checkout.title',
            href: show(),
        },
    ],
};
