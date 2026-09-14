import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, MapPin, TicketPercent } from 'lucide-react';
import { useState } from 'react';
import WholesaleCheckoutController from '@/actions/App/Http/Controllers/Erp/WholesaleCheckoutController';
import InputError from '@/components/input-error';
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
import { show as cartShow } from '@/routes/wholesale/cart';
import { show } from '@/routes/wholesale/checkout';
import type {
    CheckoutAddress,
    CheckoutAddressType,
    CheckoutSummary,
} from '@/types/wholesale';

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

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-6">
                        <SectionCard
                            title={t('wholesale.checkout.items')}
                            contentClassName="p-0"
                        >
                            <ul className="divide-border divide-y">
                                {checkout.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                    >
                                        <div className="min-w-0">
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
                                            className="font-semibold"
                                        />
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <div className="grid gap-6 md:grid-cols-2">
                            <AddressCard
                                type="billing"
                                address={checkout.addresses.billing}
                            />
                            <AddressCard
                                type="shipping"
                                address={checkout.addresses.shipping}
                                canCopyBilling={
                                    checkout.addresses.billing !== null
                                }
                            />
                        </div>

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

                    <div className="space-y-4">
                        <SectionCard title={t('wholesale.checkout.summary')}>
                            <dl className="space-y-3 text-sm">
                                <SummaryRow
                                    label={t('wholesale.checkout.subtotal')}
                                >
                                    <MoneyAmount amount={checkout.subtotal} />
                                </SummaryRow>
                                {checkout.discount.minor_units > 0 && (
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
                                {checkout.tax_added.minor_units > 0 && (
                                    <SummaryRow
                                        label={t('wholesale.checkout.tax')}
                                    >
                                        <MoneyAmount
                                            amount={checkout.tax_added}
                                        />
                                    </SummaryRow>
                                )}
                                <div className="border-border flex items-baseline justify-between gap-3 border-t pt-3">
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
                            </dl>

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
                    </div>
                </div>
            </PageContainer>
        </>
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

const ADDRESS_FIELDS = [
    { name: 'contact_name', autoComplete: 'name', required: true, max: 255 },
    {
        name: 'contact_mobile',
        autoComplete: 'tel',
        required: true,
        max: 20,
        inputMode: 'tel',
    },
    {
        name: 'line_1',
        autoComplete: 'address-line1',
        required: true,
        max: 255,
    },
    {
        name: 'line_2',
        autoComplete: 'address-line2',
        required: false,
        max: 255,
    },
    {
        name: 'area',
        autoComplete: 'address-level3',
        required: false,
        max: 255,
    },
    {
        name: 'city',
        autoComplete: 'address-level2',
        required: true,
        max: 255,
    },
    {
        name: 'district',
        autoComplete: 'address-level1',
        required: false,
        max: 255,
    },
    {
        name: 'postcode',
        autoComplete: 'postal-code',
        required: false,
        max: 16,
    },
] as const;

function AddressCard({
    type,
    address,
    canCopyBilling = false,
}: {
    type: CheckoutAddressType;
    address: CheckoutAddress | null;
    canCopyBilling?: boolean;
}) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const formOpen = editing || address === null;

    return (
        <SectionCard
            title={t(`wholesale.checkout.address.${type}`)}
            actions={
                address !== null && !editing ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        {t('wholesale.checkout.address.edit')}
                    </Button>
                ) : undefined
            }
        >
            {!formOpen && address !== null && (
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

            {formOpen && (
                <div className="space-y-4">
                    {address === null && (
                        <p className="text-muted-foreground text-sm">
                            {t(`wholesale.checkout.address.none_${type}`)}
                        </p>
                    )}

                    {type === 'shipping' && canCopyBilling && (
                        <Form
                            {...WholesaleCheckoutController.updateAddress.form(
                                type,
                            )}
                            errorBag={type}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setEditing(false)}
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="same_as_billing"
                                        value="1"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t(
                                            'wholesale.checkout.address.same_as_billing',
                                        )}
                                    </Button>
                                    <InputError
                                        message={errors.same_as_billing}
                                    />
                                </>
                            )}
                        </Form>
                    )}

                    <Form
                        {...WholesaleCheckoutController.updateAddress.form(
                            type,
                        )}
                        errorBag={type}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setEditing(false)}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                {ADDRESS_FIELDS.map((field) => {
                                    const id = `${type}-${field.name}`;
                                    const wide =
                                        field.name === 'line_1' ||
                                        field.name === 'line_2';

                                    return (
                                        <div
                                            key={field.name}
                                            className={
                                                wide
                                                    ? 'grid gap-1.5 sm:col-span-2'
                                                    : 'grid gap-1.5'
                                            }
                                        >
                                            <Label htmlFor={id}>
                                                {t(
                                                    `wholesale.checkout.address.${field.name}`,
                                                )}
                                            </Label>
                                            <Input
                                                id={id}
                                                name={field.name}
                                                autoComplete={`${type} ${field.autoComplete}`}
                                                inputMode={
                                                    'inputMode' in field
                                                        ? field.inputMode
                                                        : undefined
                                                }
                                                maxLength={field.max}
                                                required={field.required}
                                                defaultValue={
                                                    address?.[field.name] ?? ''
                                                }
                                                aria-invalid={
                                                    errors[field.name]
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <InputError
                                                message={errors[field.name]}
                                            />
                                        </div>
                                    );
                                })}

                                <div className="flex flex-wrap gap-2 sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('wholesale.checkout.address.save')}
                                    </Button>
                                    {address !== null && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setEditing(false)}
                                        >
                                            {t(
                                                'wholesale.checkout.address.cancel',
                                            )}
                                        </Button>
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            )}
        </SectionCard>
    );
}

WholesaleCheckout.layout = {
    breadcrumbs: [
        {
            title: 'Wholesale checkout',
            href: show(),
        },
    ],
};
