import { Head, Link } from '@inertiajs/react';
import { Boxes, Plus, Warehouse as WarehouseIcon } from 'lucide-react';
import { useState } from 'react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/stock';
import { index as warehousesIndex } from '@/routes/admin/inventory/warehouses';
import type { Column, Paginator } from '@/types';
import {
    STOCK_BUCKETS,
    type StockFilters,
    type StockRow,
    type StockUnit,
    type WarehouseOption,
} from '@/types/inventory';
import TrackStockDialog from './track-stock-dialog';

type Props = {
    items: Paginator<StockRow>;
    filters: StockFilters;
    warehouses: WarehouseOption[];
    units?: StockUnit[];
    can: { track: boolean };
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Central stock, per warehouse and SKU (§19).
 *
 * The six buckets are counts the server keeps; nothing here adds them up or
 * decides a state beyond reading "available". Out of stock is said in words
 * beside the figure rather than by tinting the row (§33.9).
 */
export default function AdminStock({
    items,
    filters,
    warehouses,
    units,
    can,
}: Props) {
    const { t } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['items', 'filters'] });
    const [tracking, setTracking] = useState(false);

    const filtered = Boolean(
        filters.search || filters.warehouse || filters.state,
    );

    const statePill = (row: StockRow) =>
        row.buckets.available > 0 ? (
            <StatusPill tone="success" label={t('inventory.states.in_stock')} />
        ) : (
            <StatusPill
                tone="warning"
                label={t('inventory.states.out_of_stock')}
            />
        );

    const columns: Column<StockRow>[] = [
        {
            key: 'sku',
            header: t('inventory.stock.columns.sku'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate font-mono text-sm font-medium">
                        {row.sku}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.product.name}
                    </div>
                </div>
            ),
        },
        {
            key: 'warehouse',
            header: t('inventory.stock.columns.warehouse'),
            priority: 'secondary',
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <div className="truncate text-sm">
                        {row.warehouse.name}{' '}
                        <span className="text-muted-foreground font-mono text-xs">
                            {row.warehouse.code}
                        </span>
                    </div>
                    {!row.warehouse.is_active && (
                        <StatusPill
                            tone="neutral"
                            label={t('inventory.stock.warehouse_off')}
                        />
                    )}
                </div>
            ),
        },
        ...STOCK_BUCKETS.map((bucket): Column<StockRow> => ({
            key: bucket,
            header: t(`inventory.buckets.${bucket}`),
            align: 'end',
            sortable: bucket === 'available',
            hiddenByDefault: bucket === 'sold' || bucket === 'returned',
            cell: (row) => (
                <span className="tabular-nums">{row.buckets[bucket]}</span>
            ),
        })),
        {
            key: 'state',
            header: t('inventory.stock.columns.state'),
            cell: statePill,
        },
    ];

    const emptyState =
        warehouses.length === 0 ? (
            <EmptyState
                icon={WarehouseIcon}
                title={t('inventory.stock.no_warehouses')}
                description={t('inventory.stock.no_warehouses_help')}
                action={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={warehousesIndex()}>
                            {t('nav.warehouses')}
                        </Link>
                    </Button>
                }
            />
        ) : (
            <EmptyState
                icon={Boxes}
                title={t(
                    filtered
                        ? 'inventory.stock.no_matches'
                        : 'inventory.stock.empty',
                )}
                description={t(
                    filtered
                        ? 'inventory.stock.no_matches_help'
                        : can.track
                          ? 'inventory.stock.empty_help'
                          : 'inventory.stock.empty_help_read_only',
                )}
            />
        );

    return (
        <>
            <Head title={t('inventory.stock.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.stock.title')}
                    description={t('inventory.stock.description')}
                    actions={
                        can.track && warehouses.length > 0 ? (
                            <Button onClick={() => setTracking(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('inventory.stock.track')}
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={items}
                    rowKey={(row) => row.id}
                    caption={t('inventory.stock.caption')}
                    searchPlaceholder={t('inventory.stock.search')}
                    onlyReload={['items', 'filters']}
                    filters={
                        <>
                            <select
                                aria-label={t(
                                    'inventory.stock.filter_warehouse',
                                )}
                                value={filters.warehouse ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'warehouse',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('inventory.stock.all_warehouses')}
                                </option>
                                {warehouses.map((warehouse) => (
                                    <option
                                        key={warehouse.id}
                                        value={warehouse.id}
                                    >
                                        {warehouse.name} ({warehouse.code})
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t('inventory.stock.filter_state')}
                                value={filters.state ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'state',
                                        event.target.value || undefined,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="">
                                    {t('inventory.stock.all_states')}
                                </option>
                                <option value="in_stock">
                                    {t('inventory.states.in_stock')}
                                </option>
                                <option value="out_of_stock">
                                    {t('inventory.states.out_of_stock')}
                                </option>
                            </select>
                        </>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="truncate font-mono text-sm font-medium">
                                        {row.sku}
                                    </div>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {row.product.name} ·{' '}
                                        {row.warehouse.code}
                                    </div>
                                </div>
                                {statePill(row)}
                            </div>
                            <dl className="grid grid-cols-3 gap-2 text-xs">
                                {STOCK_BUCKETS.map((bucket) => (
                                    <div key={bucket}>
                                        <dt className="text-muted-foreground">
                                            {t(`inventory.buckets.${bucket}`)}
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {row.buckets[bucket]}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}
                    emptyState={emptyState}
                />
            </PageContainer>

            {can.track && (
                <TrackStockDialog
                    open={tracking}
                    onClose={() => setTracking(false)}
                    warehouses={warehouses.filter(
                        (warehouse) => warehouse.is_active,
                    )}
                    units={units}
                />
            )}
        </>
    );
}

AdminStock.layout = {
    breadcrumbs: [
        {
            title: 'Stock',
            href: index(),
        },
    ],
};
