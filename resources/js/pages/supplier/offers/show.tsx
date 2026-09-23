import { Head } from '@inertiajs/react';
import MoneyAmount from '@/components/money-amount';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';

export default function SupplierOfferShow({
    offer,
}: {
    offer: {
        reference: string;
        product_name: string;
        status: string;
        supplier_rate: Money;
        available_quantity: number;
        rate_history: { supplier_rate: Money; effective_from: string }[];
    };
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={offer.product_name} />

            <div className="space-y-6">
                <PageHeader
                    title={offer.product_name}
                    description={offer.reference}
                    actions={
                        <StatusPill
                            tone={
                                offer.status === 'active' ? 'success' : 'danger'
                            }
                            label={
                                offer.status === 'active'
                                    ? t('supplier.offers.state_active')
                                    : t('supplier.offers.state_suspended')
                            }
                        />
                    }
                />

                <SectionCard title={t('supplier.offers.rate')}>
                    <p className="text-2xl font-semibold">
                        <MoneyAmount
                            amount={offer.supplier_rate}
                            size="large"
                        />
                    </p>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {t('supplier.offers.availability')}:{' '}
                        {offer.available_quantity.toLocaleString(locale)}
                    </p>
                </SectionCard>

                <SectionCard title={t('supplier.offers.rate_history')}>
                    <ul className="divide-border divide-y text-sm">
                        {offer.rate_history.map((change, position) => (
                            <li
                                key={position}
                                className="flex flex-wrap justify-between gap-2 py-2"
                            >
                                <MoneyAmount amount={change.supplier_rate} />
                                <span className="text-muted-foreground">
                                    {t('supplier.offers.effective_from')}{' '}
                                    {new Date(
                                        change.effective_from,
                                    ).toLocaleDateString(locale)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
        </>
    );
}
