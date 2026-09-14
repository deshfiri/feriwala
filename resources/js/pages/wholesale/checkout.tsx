import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, TicketPercent } from 'lucide-react';
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
import type { CheckoutSummary } from '@/types/wholesale';

type Props = {
    checkout: CheckoutSummary;
};

/**
 * ERP wholesale checkout (§14).
 *
 * A review of what the server priced on this request. The page never totals,
 * discounts or taxes anything: a coupon is sent as a code, and every figure shown
 * comes back from the server.
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

                    <SectionCard title={t('wholesale.checkout.summary')}>
                        <dl className="space-y-3 text-sm">
                            <div className="flex items-baseline justify-between gap-3">
                                <dt className="text-muted-foreground">
                                    {t('wholesale.checkout.subtotal')}
                                </dt>
                                <dd>
                                    <MoneyAmount amount={checkout.subtotal} />
                                </dd>
                            </div>
                            {checkout.discount.minor_units > 0 && (
                                <div className="flex items-baseline justify-between gap-3">
                                    <dt className="text-muted-foreground">
                                        {t('wholesale.checkout.discount')}
                                    </dt>
                                    <dd>
                                        <MoneyAmount
                                            amount={checkout.discount}
                                            direction="debit"
                                        />
                                    </dd>
                                </div>
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
                    </SectionCard>
                </div>
            </PageContainer>
        </>
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
