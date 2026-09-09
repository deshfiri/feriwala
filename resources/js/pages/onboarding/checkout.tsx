import { Form, Head, Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import SubmitButton from '@/components/forms/submit-button';
import MoneyAmount from '@/components/money-amount';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Money } from '@/lib/money';

type QuoteLine = {
    type: string;
    label: string;
    amount: Money;
    is_deduction: boolean;
};

/** One rate's share of the tax, behind the single Tax line (D19). */
type TaxCharge = {
    code: string;
    label: string;
    rate_basis_points: number;
    mode: string;
    net: Money;
    tax: Money;
    gross: Money;
};

type Quote = {
    lines: QuoteLine[];
    subtotal: Money;
    total: Money;
    currency: string;
    is_payable: boolean;
    tax: TaxCharge[];
    tax_included: Money;
};

/**
 * The activation checkout (§9).
 *
 * Every component is itemised — registration fee, package fee, discount, tax,
 * deposit, gateway charge. §9 requires them stored separately, and showing them
 * separately is the same courtesy: someone should be able to see what each part
 * of their first payment is for.
 *
 * No total is computed here. The figures arrive calculated and this only
 * renders them (§36.1).
 */
export default function Checkout({
    package: pkg,
    quote,
    coupon,
    gateways,
}: {
    package: { slug: string; name: string; validity_days: number | null };
    quote: Quote;
    /** The code held for this checkout, accepted or refused with a reason. */
    coupon?: {
        accepted: boolean;
        code: string | null;
        name: string | null;
        discount: Money | null;
        reason: string | null;
    } | null;
    gateways: { name: string; label: string }[];
}) {
    const { t } = useTranslation();
    const [gateway, setGateway] = useState(gateways[0]?.name ?? '');

    return (
        <>
            <Head title="Checkout" />

            <div className="mx-auto w-full max-w-lg space-y-6 px-4 py-10">
                <header className="space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Complete your activation
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {pkg.name}
                        {pkg.validity_days && ` · ${pkg.validity_days} days`}
                    </p>
                </header>

                <section className="bg-card border-border overflow-hidden rounded-xl border shadow-sm">
                    <dl className="divide-border divide-y">
                        {quote.lines.map((line) => (
                            <div
                                key={line.type}
                                className="flex items-center justify-between gap-4 px-4 py-2.5"
                            >
                                <dt className="text-sm">{line.label}</dt>
                                <dd>
                                    <MoneyAmount
                                        amount={line.amount}
                                        direction={
                                            line.is_deduction
                                                ? 'credit'
                                                : 'neutral'
                                        }
                                        showSign={line.is_deduction}
                                    />
                                </dd>
                            </div>
                        ))}

                        <div className="bg-muted flex items-center justify-between gap-4 px-4 py-3">
                            <dt className="text-sm font-semibold">
                                Total payable
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={quote.total}
                                    size="large"
                                />
                            </dd>
                        </div>
                    </dl>

                    {/*
                        The tax behind the single Tax line, per rate (D19). A
                        rolled-up figure cannot answer "at what rate, on what",
                        which is the question anybody filing a return has.
                        Inclusive tax is shown here and nowhere else: it is
                        already inside the fees above, and a second line would
                        read as a second charge.
                    */}
                    {quote.tax.length > 0 && (
                        <dl className="border-border divide-border divide-y border-t">
                            {quote.tax.map((charge) => (
                                <div
                                    key={`${charge.code}-${charge.rate_basis_points}-${charge.mode}`}
                                    className="text-muted-foreground flex items-center justify-between gap-4 px-4 py-2 text-xs"
                                >
                                    <dt>
                                        {t('billing.tax.checkout_line', {
                                            label: charge.label,
                                            net: charge.net.formatted,
                                        })}
                                        {charge.mode === 'inclusive' &&
                                            ` · ${t('billing.tax.checkout_included', { amount: charge.tax.formatted })}`}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {charge.tax.formatted}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </section>

                {/*
                    Applying a code recalculates the quote and reserves nothing:
                    a coupon is held when somebody commits to paying, not when
                    they look at the page (§9). The refusal says which part
                    failed, because "not valid" gives nobody a next step.
                */}
                <Form
                    action="/checkout/coupon"
                    method="post"
                    className="bg-card border-border space-y-3 rounded-xl border p-5 shadow-sm"
                >
                    {({ processing }) => (
                        <>
                            <Label htmlFor="coupon-code">
                                {t('billing.coupons.label')}
                            </Label>

                            <div className="flex gap-2">
                                <Input
                                    id="coupon-code"
                                    name="code"
                                    defaultValue={coupon?.code ?? ''}
                                    placeholder={t(
                                        'billing.coupons.placeholder',
                                    )}
                                    className="font-mono uppercase"
                                />

                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    {t('billing.coupons.apply')}
                                </Button>
                            </div>

                            {coupon?.accepted === true && (
                                <p
                                    className="text-sm font-medium"
                                    role="status"
                                >
                                    {t('billing.coupons.applied', {
                                        code: coupon.code ?? '',
                                    })}
                                </p>
                            )}

                            {coupon?.accepted === false &&
                                coupon.reason !== null && (
                                    <p
                                        className="text-danger text-sm font-medium"
                                        role="status"
                                    >
                                        {coupon.reason}
                                    </p>
                                )}
                        </>
                    )}
                </Form>

                <Form
                    action="/checkout"
                    method="post"
                    className="bg-card border-border space-y-4 rounded-xl border p-5 shadow-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <fieldset className="space-y-2">
                                <legend className="mb-2 text-sm font-medium">
                                    How would you like to pay?
                                </legend>

                                {gateways.map((option) => (
                                    <label
                                        key={option.name}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm',
                                            gateway === option.name
                                                ? 'border-brand bg-brand-subtle'
                                                : 'border-input hover:bg-muted',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="gateway"
                                            value={option.name}
                                            checked={gateway === option.name}
                                            onChange={() =>
                                                setGateway(option.name)
                                            }
                                            className="accent-brand"
                                        />
                                        <span className="font-medium">
                                            {option.label}
                                        </span>
                                    </label>
                                ))}

                                {gateways.length === 0 && (
                                    <p className="text-danger text-sm">
                                        No payment method is available right
                                        now. Please contact support.
                                    </p>
                                )}
                            </fieldset>

                            {errors.gateway && (
                                <p className="text-danger text-sm font-medium">
                                    {errors.gateway}
                                </p>
                            )}

                            {/* The amount is restated next to the button — the
                                last chance to notice it is wrong. */}
                            <SubmitButton
                                processing={processing}
                                processingLabel="Taking you to pay…"
                                disabled={
                                    gateways.length === 0 || !quote.is_payable
                                }
                                className="w-full"
                            >
                                Pay {quote.total.formatted}
                            </SubmitButton>

                            <p className="text-muted-foreground flex items-center justify-center gap-1.5 text-xs">
                                <Lock className="size-3" aria-hidden="true" />
                                You will be taken to your bank or wallet to
                                complete this securely.
                            </p>
                        </>
                    )}
                </Form>

                <p className="text-center">
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/packages">Choose a different package</Link>
                    </Button>
                </p>
            </div>
        </>
    );
}
