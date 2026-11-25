import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ConfirmButton from '@/components/confirm/confirm-button';
import MoneyInput from '@/components/money-input';
import {
    StorefrontProfitCard,
    type StorefrontProfitSummary,
} from '@/components/storefront-profit-card';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { store as storeCod } from '@/routes/admin/orders/storefront-cod-collection';
import { show as showProfit } from '@/routes/admin/storefront-profits';

export type AdminStorefrontProfit = {
    summary: StorefrontProfitSummary | null;
    id: string | null;
    can_confirm_cod: boolean;
    total: Money;
};

/**
 * A Storefront order's Partner profit on the admin order page, and the one
 * place staff confirm the cash collected on delivery for the whole order.
 */
export default function AdminStorefrontProfitPanel({
    orderId,
    data,
}: {
    orderId: string;
    data: AdminStorefrontProfit;
}) {
    const { t } = useTranslation();
    const form = useForm({ amount_collected: data.total.amount });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeCod.url(orderId), { preserveScroll: true });
    };

    return (
        <div className="space-y-3">
            <StorefrontProfitCard profit={data.summary} />

            {data.id && (
                <Button asChild variant="outline" size="sm">
                    <Link href={showProfit(data.id)}>
                        {t('storefront_profit.actions.view')}
                    </Link>
                </Button>
            )}

            {data.can_confirm_cod && (
                <form
                    onSubmit={submit}
                    className="space-y-2 rounded-xl border p-4"
                >
                    <MoneyInput
                        id="storefront-cod-amount"
                        name="amount_collected"
                        label={t('storefront_profit.actions.confirm_cod')}
                        value={form.data.amount_collected}
                        onChange={(value) =>
                            form.setData('amount_collected', value)
                        }
                        error={form.errors.amount_collected}
                        required
                    />
                    <ConfirmButton
                        type="submit"
                        size="sm"
                        disabled={form.processing}
                        confirm={{
                            title: t('storefront_profit.confirm.cod_title'),
                            description: t(
                                'storefront_profit.confirm.cod_body',
                            ),
                            destructive: false,
                        }}
                    >
                        {t('storefront_profit.actions.confirm_cod')}
                    </ConfirmButton>
                </form>
            )}
        </div>
    );
}
