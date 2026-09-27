import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index } from '@/routes/supplier/allocations';
import { show as showPayable } from '@/routes/supplier/payables';

type Payable = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    status_tone: string;
    gross_amount: Money;
    net_amount: Money;
    delivered_at: string | null;
    payment_settled_at: string | null;
    eligible_at: string | null;
};

type Allocation = {
    id: string;
    order_reference: string;
    order_status: string;
    placed_at: string | null;
    product_name: string;
    variant: string | null;
    quantity: number;
    supplier_rate: Money | null;
    allocated_at: string | null;
    payable: Payable | null;
};

export default function SupplierAllocationShow({
    allocation,
}: {
    allocation: Allocation;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={allocation.product_name} />

            <PageContainer width="narrow">
                <PageHeader
                    title={allocation.product_name}
                    description={allocation.order_reference}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('supplier.allocations.back')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard title={t('supplier.allocations.details')}>
                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.allocations.quantity')}
                            </dt>
                            <dd>
                                {allocation.quantity.toLocaleString(locale)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.allocations.rate')}
                            </dt>
                            <dd>
                                {allocation.supplier_rate ? (
                                    <MoneyAmount
                                        amount={allocation.supplier_rate}
                                        size="large"
                                    />
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.allocations.allocated_at')}
                            </dt>
                            <dd>
                                {allocation.allocated_at
                                    ? new Date(
                                          allocation.allocated_at,
                                      ).toLocaleString(locale)
                                    : '—'}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                {allocation.payable && (
                    <SectionCard
                        title={t('supplier.allocations.payable_status')}
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={showPayable(allocation.payable.id)}>
                                    {t('supplier.listings.open')}
                                </Link>
                            </Button>
                        }
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <StatusPill
                                tone={allocation.payable.status_tone as never}
                                label={allocation.payable.status_label}
                            />
                            <MoneyAmount
                                amount={allocation.payable.net_amount}
                                size="large"
                            />
                        </div>
                        <p className="text-muted-foreground mt-2 text-xs">
                            {allocation.payable.delivered_at
                                ? t('supplier.payables.delivered')
                                : t('supplier.payables.awaiting_delivery')}
                            {' · '}
                            {allocation.payable.payment_settled_at
                                ? t('supplier.payables.payment_settled')
                                : t('supplier.payables.awaiting_payment')}
                        </p>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
