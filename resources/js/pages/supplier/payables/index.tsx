import { Head, Link } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { show } from '@/routes/supplier/payables';
import type { Column, Paginator } from '@/types';

type Row = {
    id: string;
    reference: string;
    order_reference: string;
    product_name: string;
    variant_label: string | null;
    quantity: number;
    net_amount: Money;
    status: string;
    status_label: string;
    status_tone: string;
    created_at: string;
};

/**
 * A Supplier's own payables (D25, P13-22). Never another Supplier's, and
 * never the platform margin — only the gross and net amount this Supplier is
 * owed for what it delivered.
 */
export default function SupplierPayablesIndex({
    payables,
}: {
    payables: Paginator<Row>;
}) {
    const { t, locale } = useTranslation();

    const columns: Column<Row>[] = [
        {
            key: 'product',
            header: t('supplier.payables.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">
                        {row.product_name}
                        {row.variant_label ? ` · ${row.variant_label}` : ''}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {row.reference} · {row.order_reference}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: t('supplier.payables.amount'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.net_amount} />,
        },
        {
            key: 'status',
            header: t('supplier.payables.status'),
            cell: (row) => (
                <StatusPill
                    tone={row.status_tone as never}
                    label={row.status_label}
                />
            ),
        },
        {
            key: 'created_at',
            header: t('supplier.payables.created_at'),
            priority: 'secondary',
            cell: (row) => new Date(row.created_at).toLocaleDateString(locale),
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
            <Head title={t('supplier.payables.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.payables.title')}
                    description={t('supplier.payables.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={payables}
                    rowKey={(row) => row.id}
                    caption={t('supplier.payables.title')}
                    onlyReload={['payables']}
                    emptyState={
                        <EmptyState
                            icon={Coins}
                            title={t('supplier.payables.empty_title')}
                            description={t(
                                'supplier.payables.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}
