import { Head, Link } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { show } from '@/routes/supplier/allocations';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    order_reference: string;
    order_status: string;
    placed_at: string | null;
    product_name: string;
    variant: string | null;
    quantity: number;
    supplier_rate: Money | null;
    payable: { status_label: string; status_tone: string } | null;
};

/**
 * A Supplier's own allocated order lines (D25, P13-21). Never another
 * Supplier's line, and never the Platform Rate or margin — only what this
 * Supplier agreed to be paid.
 */
export default function SupplierAllocationsIndex({
    allocations,
}: {
    allocations: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.allocations.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {row.order_reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'quantity',
            header: t('supplier.allocations.quantity'),
            align: 'end',
            cell: (row) => row.quantity.toLocaleString(locale),
        },
        {
            key: 'rate',
            header: t('supplier.allocations.rate'),
            align: 'end',
            priority: 'secondary',
            cell: (row) =>
                row.supplier_rate ? (
                    <MoneyAmount amount={row.supplier_rate} />
                ) : (
                    '—'
                ),
        },
        {
            key: 'payable',
            header: t('supplier.allocations.payable_status'),
            cell: (row) =>
                row.payable ? (
                    <StatusPill
                        tone={row.payable.status_tone as never}
                        label={row.payable.status_label}
                    />
                ) : (
                    '—'
                ),
        },
        {
            key: 'placed_at',
            header: t('supplier.allocations.placed_at'),
            priority: 'secondary',
            cell: (row) =>
                row.placed_at
                    ? new Date(row.placed_at).toLocaleDateString(locale)
                    : '—',
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
            <Head title={t('supplier.allocations.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.allocations.title')}
                    description={t('supplier.allocations.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={allocations}
                    rowKey={(row) => row.id}
                    caption={t('supplier.allocations.title')}
                    onlyReload={['allocations']}
                    emptyState={
                        <EmptyState
                            icon={PackageSearch}
                            title={t('supplier.allocations.empty_title')}
                            description={t(
                                'supplier.allocations.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
