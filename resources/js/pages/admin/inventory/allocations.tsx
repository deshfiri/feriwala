import { Head, Link } from '@inertiajs/react';
import { PackageCheck, Unlock } from 'lucide-react';
import { useState } from 'react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/allocations';
import { show as stockItem } from '@/routes/admin/inventory/stock';
import type { Column, Paginator } from '@/types';
import type { StockAllocationListRow } from '@/types/inventory';
import ReleaseAllocationDialog from './release-allocation-dialog';

type Props = {
    allocations: Paginator<StockAllocationListRow>;
    filters: { search: string | null; held: 'held' | 'all' };
    can: { release: boolean };
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Central stock set aside for business accounts, across every stock item
 * (§19: user-allocated stock, P3-30).
 *
 * Allocating happens on the stock item, where the available figure is in view;
 * this list is where somebody finds what each account holds and gives it back.
 */
export default function AdminStockAllocations({
    allocations,
    filters,
    can,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['allocations', 'filters'] });
    const [releasing, setReleasing] = useState<StockAllocationListRow | null>(
        null,
    );

    const filtered = Boolean(filters.search) || filters.held === 'all';

    const accountCell = (row: StockAllocationListRow) => (
        <div className="min-w-0 space-y-1">
            <div className="truncate text-sm font-medium">
                {row.account.name}
            </div>
            {!row.account.can_trade && (
                <StatusPill
                    tone="warning"
                    label={t('inventory.allocations.account_cannot_trade')}
                />
            )}
        </div>
    );

    const releaseButton = (row: StockAllocationListRow) =>
        can.release && row.quantity > 0 ? (
            <Button
                variant="outline"
                size="sm"
                onClick={() => setReleasing(row)}
            >
                <Unlock className="size-4" aria-hidden="true" />
                {t('inventory.allocations.release')}
            </Button>
        ) : null;

    const columns: Column<StockAllocationListRow>[] = [
        {
            key: 'account',
            header: t('inventory.allocations.columns.account'),
            cell: accountCell,
        },
        {
            key: 'sku',
            header: t('inventory.allocations.columns.sku'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={stockItem(row.item_id)}
                        className="block truncate font-mono text-sm underline-offset-4 hover:underline"
                    >
                        {row.sku}
                    </Link>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.product}
                    </div>
                </div>
            ),
        },
        {
            key: 'warehouse',
            header: t('inventory.allocations.columns.warehouse'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm">
                    {row.warehouse.name}{' '}
                    <span className="text-muted-foreground font-mono text-xs">
                        {row.warehouse.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'quantity',
            header: t('inventory.allocations.columns.quantity'),
            align: 'end',
            cell: (row) => <span className="tabular-nums">{row.quantity}</span>,
        },
        {
            key: 'updated_at',
            header: t('inventory.allocations.columns.updated'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-sm whitespace-nowrap">
                    {new Date(row.updated_at).toLocaleString(locale)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: releaseButton,
        },
    ];

    return (
        <>
            <Head title={t('inventory.allocations.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.allocations.title')}
                    description={t('inventory.allocations.description')}
                />

                <DataTable
                    columns={columns}
                    paginator={allocations}
                    rowKey={(row) => row.id}
                    caption={t('inventory.allocations.caption')}
                    searchPlaceholder={t('inventory.allocations.search')}
                    onlyReload={['allocations', 'filters']}
                    filters={
                        <select
                            aria-label={t('inventory.allocations.filter_held')}
                            value={filters.held}
                            onChange={(event) =>
                                setFilter(
                                    'held',
                                    event.target.value === 'all'
                                        ? 'all'
                                        : undefined,
                                )
                            }
                            className={selectClass}
                        >
                            <option value="held">
                                {t('inventory.allocations.held')}
                            </option>
                            <option value="all">
                                {t('inventory.allocations.all')}
                            </option>
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                {accountCell(row)}
                                <span className="text-sm font-medium whitespace-nowrap tabular-nums">
                                    {t('inventory.allocations.units', {
                                        count: row.quantity,
                                    })}
                                </span>
                            </div>
                            <div className="text-sm">
                                <Link
                                    href={stockItem(row.item_id)}
                                    className="font-mono underline-offset-4 hover:underline"
                                >
                                    {row.sku}
                                </Link>{' '}
                                <span className="text-muted-foreground text-xs">
                                    {row.product} · {row.warehouse.code}
                                </span>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-muted-foreground text-xs">
                                    {t('inventory.allocations.changed', {
                                        time: new Date(
                                            row.updated_at,
                                        ).toLocaleString(locale),
                                    })}
                                </span>
                                {releaseButton(row)}
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={PackageCheck}
                            title={t(
                                filtered
                                    ? 'inventory.allocations.no_matches'
                                    : 'inventory.allocations.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'inventory.allocations.no_matches_help'
                                    : 'inventory.allocations.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>

            {can.release && (
                <ReleaseAllocationDialog
                    allocation={releasing}
                    sku={releasing?.sku ?? ''}
                    onClose={() => setReleasing(null)}
                />
            )}
        </>
    );
}

AdminStockAllocations.layout = {
    breadcrumbs: [
        {
            title: 'Stock allocations',
            href: index(),
        },
    ],
};
