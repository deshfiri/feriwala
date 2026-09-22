import { Head, Link } from '@inertiajs/react';
import { Wallet as WalletIcon } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { index, show } from '@/routes/admin/supplier-wallets';
import type { Column, Paginator } from '@/types';

const RELOAD_PROPS = ['wallets'];

type Row = {
    id: string;
    supplier: string;
    supplier_id: string;
    currency: string;
    total: Money;
    available: Money;
    reserved: Money;
    recovery: Money;
    has_outstanding_recovery: boolean;
};

export default function AdminSupplierWalletsIndex({
    wallets,
}: {
    wallets: Paginator<Row>;
}) {
    const { t } = useTranslation();
    const { getFilter, setFilter } = useTableQuery({ only: RELOAD_PROPS });

    const columns: Column<Row>[] = [
        {
            key: 'supplier',
            header: t('supplier.admin.wallets.columns.supplier'),
            cell: (row) => row.supplier,
        },
        {
            key: 'total',
            header: t('supplier.admin.wallets.columns.total'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.total} />,
        },
        {
            key: 'available',
            header: t('supplier.admin.wallets.columns.available'),
            align: 'end',
            cell: (row) => (
                <MoneyAmount amount={row.available} direction="credit" />
            ),
        },
        {
            key: 'reserved',
            header: t('supplier.admin.wallets.columns.reserved'),
            align: 'end',
            priority: 'secondary',
            cell: (row) => <MoneyAmount amount={row.reserved} />,
        },
        {
            key: 'recovery',
            header: t('supplier.admin.wallets.columns.recovery'),
            align: 'end',
            cell: (row) =>
                row.has_outstanding_recovery ? (
                    <span className="flex items-center justify-end gap-2">
                        <MoneyAmount amount={row.recovery} direction="debit" />
                        <StatusPill tone="warning" label="!" />
                    </span>
                ) : (
                    <MoneyAmount amount={row.recovery} />
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
                        {t('supplier.admin.suppliers.open')}
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={t('supplier.admin.wallets.title')} />

            <PageContainer>
                <PageHeader
                    title={t('supplier.admin.wallets.title')}
                    description={t('supplier.admin.wallets.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={wallets}
                    rowKey={(row) => row.id}
                    caption={t('supplier.admin.wallets.title')}
                    onlyReload={RELOAD_PROPS}
                    filters={
                        <Input
                            className="h-8 w-40"
                            placeholder={t(
                                'supplier.admin.allocations.supplier_filter',
                            )}
                            value={getFilter('supplier') ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'supplier',
                                    event.target.value || undefined,
                                )
                            }
                        />
                    }
                    emptyState={
                        <EmptyState
                            icon={WalletIcon}
                            title={t('supplier.admin.wallets.empty_title')}
                            description={t(
                                'supplier.admin.wallets.empty_description',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminSupplierWalletsIndex.layout = {
    breadcrumbs: [{ title: 'Supplier wallets', href: index() }],
};
