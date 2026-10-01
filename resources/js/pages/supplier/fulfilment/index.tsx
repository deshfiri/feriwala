import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';
import { show } from '@/routes/supplier/fulfilment';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    product_name: string;
    sku: string;
    quantity: number;
    supply_mode_label: string;
    due_at: string | null;
    confirmation_due_at: string | null;
};

/**
 * A Supplier's own fulfilment commitments (Advanced Order Management batch,
 * Commit 2) — capacity reservations standing in for physical stock on an
 * on_demand/pre_order offer, never another Supplier's line.
 */
export default function SupplierFulfilmentIndex({
    commitments,
}: {
    commitments: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.fulfilment.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                    </div>
                    <div className="text-muted-foreground font-mono text-xs">
                        {row.sku}
                    </div>
                </div>
            ),
        },
        {
            key: 'quantity',
            header: t('supplier.fulfilment.quantity'),
            align: 'end',
            cell: (row) => row.quantity.toLocaleString(locale),
        },
        {
            key: 'supply_mode',
            header: t('supplier.fulfilment.supply_mode'),
            priority: 'secondary',
            cell: (row) => row.supply_mode_label,
        },
        {
            key: 'status',
            header: t('supplier.payables.status'),
            cell: (row) => (
                <StatusPill tone={row.status_tone} label={row.status_label} />
            ),
        },
        {
            key: 'confirm_by',
            header: t('supplier.fulfilment.confirm_by_header'),
            priority: 'secondary',
            cell: (row) =>
                row.confirmation_due_at
                    ? new Date(row.confirmation_due_at).toLocaleDateString(
                          locale,
                      )
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
            <Head title={t('supplier.fulfilment.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.fulfilment.title')}
                    description={t('supplier.fulfilment.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={commitments}
                    rowKey={(row) => row.id}
                    caption={t('supplier.fulfilment.title')}
                    onlyReload={['commitments']}
                    emptyState={
                        <EmptyState
                            icon={ClipboardCheck}
                            title={t('supplier.fulfilment.empty_title')}
                            description={t(
                                'supplier.fulfilment.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
