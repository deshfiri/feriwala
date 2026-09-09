import { Form } from '@inertiajs/react';
import { useState } from 'react';
import BillingController from '@/actions/App/Http/Controllers/Admin/BillingController';
import InputError from '@/components/input-error';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

export type CouponRow = {
    id: string;
    code: string;
    name: string;
    discount_type: string;
    discount_label: string;
    applies_to: string;
    applies_to_label: string;
    package: string | null;
    usage_limit: number | null;
    redeemed_count: number;
    per_account_limit: number | null;
    effective_from: string;
    effective_until: string | null;
    is_active: boolean;
    is_open: boolean;
};

type Option = { value: string; label: string };

/**
 * Coupons and promotional discounts (§9).
 *
 * The used-of-limit figure is the one an administrator actually watches, so it
 * is on the row rather than behind a click — a promotion that has quietly run
 * out is the thing they need to see first.
 *
 * Withdrawing closes a coupon rather than deleting it: redemptions reference it,
 * and a promotion that vanishes takes the explanation of every discounted total
 * with it.
 */
export default function BillingCoupons({
    coupons,
    packages,
    discountTypes,
    scopes,
    canManage,
}: {
    coupons: CouponRow[];
    packages: { id: string; name: string }[];
    discountTypes: Option[];
    scopes: Option[];
    canManage: boolean;
}) {
    const { t, locale } = useTranslation();
    const [adding, setAdding] = useState(false);

    const date = (value: string | null) =>
        value === null ? '—' : new Date(value).toLocaleDateString(locale);

    const state = (coupon: CouponRow) => {
        if (coupon.is_open) {
            return {
                tone: 'success' as const,
                label: t('billing.coupons.open'),
            };
        }

        return coupon.is_active
            ? { tone: 'info' as const, label: t('billing.coupons.scheduled') }
            : { tone: 'neutral' as const, label: t('billing.coupons.ended') };
    };

    return (
        <SectionCard
            title={t('billing.coupons.title')}
            description={t('billing.coupons.description')}
            actions={
                canManage ? (
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setAdding((open) => !open)}
                    >
                        {t('billing.coupons.add')}
                    </Button>
                ) : undefined
            }
            contentClassName="p-0"
        >
            {coupons.length === 0 ? (
                <p className="text-muted-foreground px-5 py-4 text-sm">
                    {t('billing.coupons.empty')}
                </p>
            ) : (
                <ul className="divide-border divide-y text-sm">
                    {coupons.map((coupon) => (
                        <li
                            key={coupon.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                        >
                            <div className="min-w-0">
                                <p className="font-medium">
                                    <span className="font-mono">
                                        {coupon.code}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {coupon.name}
                                    </span>
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {coupon.discount_label} ·{' '}
                                    {coupon.applies_to_label}
                                    {coupon.package !== null &&
                                        ` · ${coupon.package}`}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {date(coupon.effective_from)} —{' '}
                                    {date(coupon.effective_until)} ·{' '}
                                    {coupon.usage_limit === null
                                        ? t('billing.coupons.used_unlimited', {
                                              used: coupon.redeemed_count,
                                          })
                                        : t('billing.coupons.used', {
                                              used: coupon.redeemed_count,
                                              limit: coupon.usage_limit,
                                          })}
                                </p>
                            </div>

                            <div className="flex items-center gap-3">
                                <StatusPill
                                    tone={state(coupon).tone}
                                    label={state(coupon).label}
                                />

                                {canManage && coupon.is_active && (
                                    <Form
                                        {...BillingController.withdrawCoupon.form(
                                            coupon.id,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {t('billing.coupons.close')}
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canManage && adding && (
                <Form
                    {...BillingController.storeCoupon.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setAdding(false)}
                    resetOnSuccess
                    className="border-border space-y-4 border-t p-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-code">
                                        {t('billing.coupons.code')}
                                    </Label>
                                    <Input
                                        id="coupon-code"
                                        name="code"
                                        required
                                        maxLength={40}
                                    />
                                    <InputError message={errors.code} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-name">
                                        {t('billing.coupons.name')}
                                    </Label>
                                    <Input
                                        id="coupon-name"
                                        name="name"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-type">
                                        {t('billing.coupons.type')}
                                    </Label>
                                    <select
                                        id="coupon-type"
                                        name="discount_type"
                                        required
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        {discountTypes.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-value">
                                        {t('billing.coupons.value')}
                                    </Label>
                                    <Input
                                        id="coupon-value"
                                        name="value"
                                        type="number"
                                        min={1}
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t(
                                            'billing.coupons.value_percentage_help',
                                        )}{' '}
                                        {t('billing.coupons.value_fixed_help')}
                                    </p>
                                    <InputError message={errors.value} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-scope">
                                        {t('billing.coupons.applies_to')}
                                    </Label>
                                    <select
                                        id="coupon-scope"
                                        name="applies_to"
                                        required
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        {scopes.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-package">
                                        {t('billing.coupons.package')}
                                    </Label>
                                    <select
                                        id="coupon-package"
                                        name="package"
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                    >
                                        <option value="">
                                            {t('billing.coupons.package_any')}
                                        </option>
                                        {packages.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-min">
                                        {t('billing.coupons.minimum_spend')}
                                    </Label>
                                    <Input
                                        id="coupon-min"
                                        name="minimum_spend_minor"
                                        type="number"
                                        min={0}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-max">
                                        {t('billing.coupons.maximum_discount')}
                                    </Label>
                                    <Input
                                        id="coupon-max"
                                        name="maximum_discount_minor"
                                        type="number"
                                        min={0}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-uses">
                                        {t('billing.coupons.usage_limit')}
                                    </Label>
                                    <Input
                                        id="coupon-uses"
                                        name="usage_limit"
                                        type="number"
                                        min={1}
                                        placeholder={t(
                                            'billing.coupons.usage_unlimited',
                                        )}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-per-account">
                                        {t('billing.coupons.per_account_limit')}
                                    </Label>
                                    <Input
                                        id="coupon-per-account"
                                        name="per_account_limit"
                                        type="number"
                                        min={1}
                                        defaultValue={1}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-from">
                                        {t('billing.coupons.effective_from')}
                                    </Label>
                                    <Input
                                        id="coupon-from"
                                        name="effective_from"
                                        type="date"
                                        required
                                    />
                                    <InputError
                                        message={errors.effective_from}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="coupon-until">
                                        {t('billing.coupons.effective_until')}
                                    </Label>
                                    <Input
                                        id="coupon-until"
                                        name="effective_until"
                                        type="date"
                                    />
                                    <InputError
                                        message={errors.effective_until}
                                    />
                                </div>
                            </div>

                            <Button type="submit" disabled={processing}>
                                {t('billing.coupons.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </SectionCard>
    );
}
