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
import { index } from '@/routes/admin/supplier-allocations';
import { show as showPayable } from '@/routes/admin/supplier-payables';

type Allocation = {
    id: string;
    order_reference: string;
    order_status: string;
    placed_at: string | null;
    supplier: string;
    offer_reference: string;
    product_name: string;
    variant: string | null;
    quantity: number;
    supplier_rate: Money;
    platform_rate: Money;
    platform_margin: Money;
    allocated_at: string | null;
    payable: {
        id: string;
        reference: string;
        status: string;
        status_label: string;
        status_tone: string;
    } | null;
};

export default function AdminSupplierAllocationShow({
    allocation,
}: {
    allocation: Allocation;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={allocation.product_name} />

            <PageContainer>
                <PageHeader
                    title={allocation.product_name}
                    description={`${allocation.order_reference} · ${allocation.supplier}`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('supplier.admin.suppliers.back')}
                            </Link>
                        </Button>
                    }
                />

                <SectionCard
                    title={t('supplier.admin.allocations.title')}
                    description={allocation.offer_reference}
                >
                    <dl className="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t(
                                    'supplier.admin.allocations.columns.quantity',
                                )}
                            </dt>
                            <dd>
                                {allocation.quantity.toLocaleString(locale)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t(
                                    'supplier.admin.offers.columns.supplier_rate',
                                )}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={allocation.supplier_rate}
                                    size="large"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t(
                                    'supplier.admin.offers.columns.platform_rate',
                                )}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={allocation.platform_rate}
                                    size="large"
                                />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">
                                {t('supplier.admin.offers.columns.margin')}
                            </dt>
                            <dd>
                                <MoneyAmount
                                    amount={allocation.platform_margin}
                                    size="large"
                                />
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
                                    {t('supplier.admin.suppliers.open')}
                                </Link>
                            </Button>
                        }
                    >
                        <StatusPill
                            tone={allocation.payable.status_tone as never}
                            label={allocation.payable.status_label}
                        />
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

AdminSupplierAllocationShow.layout = {
    breadcrumbs: [{ title: 'nav.supplier_allocations', href: index() }],
};
