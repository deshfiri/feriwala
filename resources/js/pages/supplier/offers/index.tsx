import { Head, Link } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { show } from '@/routes/supplier/offers';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    product_name: string;
    status: string;
    is_preferred: boolean;
    supplier_rate: Money;
    available_quantity: number;
};

/**
 * Approved products and the Supplier's own rates. Shows only what the
 * Supplier agreed to be paid — never the platform's resale rate or margin.
 */
export default function SupplierOffersIndex({
    offers,
}: {
    offers: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.offers.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {row.reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'rate',
            header: t('supplier.offers.rate'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.supplier_rate} />,
        },
        {
            key: 'availability',
            header: t('supplier.offers.availability'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => row.available_quantity.toLocaleString(locale),
        },
        {
            key: 'status',
            header: t('supplier.offers.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status === 'active' ? 'success' : 'danger'}
                    label={
                        row.status === 'active'
                            ? t('supplier.offers.state_active')
                            : t('supplier.offers.state_suspended')
                    }
                />
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            align: 'end',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>
                        {t('supplier.listings.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.offers.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('supplier.offers.title')}
                    description={t('supplier.offers.rates_description')}
                />

                <DataTable
                    columns={columns}
                    paginator={offers}
                    rowKey={(row) => row.id}
                    caption={t('supplier.offers.title')}
                    onlyReload={['offers']}
                    emptyState={
                        <EmptyState
                            icon={PackageCheck}
                            title={t('supplier.offers.empty_title')}
                            description={t('supplier.offers.empty_description')}
                        />
                    }
                />
            </div>
        </>
    );
}
