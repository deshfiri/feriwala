import { Head } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/inventory/availability';
import type { Column, Paginator } from '@/types';

type UnitAvailability = {
    sku: string;
    in_stock: boolean;
    quantity: number;
    updated_at: string | null;
};

type ProductAvailability = {
    id: string;
    name: string;
    sku: string;
    status_label: string;
    units: UnitAvailability[];
};

type Props = {
    products: Paginator<ProductAvailability>;
    filters: { search: string | null; state: string | null };
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * What every website is told about stock (§19.1, contract §5.2, P3-27).
 *
 * One availability per SKU, shared by every website, shown exactly as a website
 * receives it: the preview is the server's own answer, not something assembled
 * here, so the screen and the storefront cannot disagree.
 */
export default function AdminAvailability({ products, filters }: Props) {
    const { t } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['products', 'filters'] });

    const filtered = Boolean(filters.search || filters.state);

    const units = (row: ProductAvailability) => (
        <ul className="space-y-1">
            {row.units.map((unit) => (
                <li
                    key={unit.sku}
                    className="flex flex-wrap items-center justify-between gap-2"
                >
                    <span className="font-mono text-sm">{unit.sku}</span>
                    <span className="flex items-center gap-2">
                        <span className="text-sm tabular-nums">
                            {t('inventory.availability.units_available', {
                                count: unit.quantity,
                            })}
                        </span>
                        <StatusPill
                            tone={unit.in_stock ? 'success' : 'warning'}
                            label={t(
                                unit.in_stock
                                    ? 'inventory.states.in_stock'
                                    : 'inventory.states.out_of_stock',
                            )}
                        />
                    </span>
                </li>
            ))}
        </ul>
    );

    const preview = (row: ProductAvailability) => (
        <details className="text-xs">
            <summary className="text-muted-foreground cursor-pointer">
                {t('inventory.availability.preview')}
            </summary>
            <pre className="bg-muted mt-2 overflow-x-auto rounded-lg border p-3 font-mono">
                {JSON.stringify(row.units, null, 2)}
            </pre>
        </details>
    );

    const columns: Column<ProductAvailability>[] = [
        {
            key: 'product',
            header: t('inventory.availability.columns.product'),
            cell: (row) => (
                <div className="min-w-0 space-y-1">
                    <div className="truncate font-medium">{row.name}</div>
                    <div className="text-muted-foreground truncate text-xs">
                        {row.sku} · {row.status_label}
                    </div>
                </div>
            ),
        },
        {
            key: 'units',
            header: t('inventory.availability.columns.units'),
            cell: (row) => (
                <div className="min-w-64 space-y-2">
                    {units(row)}
                    {preview(row)}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('inventory.availability.title')} />

            <PageContainer>
                <PageHeader
                    title={t('inventory.availability.title')}
                    description={t('inventory.availability.description')}
                />

                <p className="text-muted-foreground text-sm">
                    {t('inventory.availability.advisory')}
                </p>

                <DataTable
                    columns={columns}
                    paginator={products}
                    rowKey={(row) => row.id}
                    caption={t('inventory.availability.caption')}
                    searchPlaceholder={t('inventory.availability.search')}
                    onlyReload={['products', 'filters']}
                    filters={
                        <select
                            aria-label={t(
                                'inventory.availability.filter_state',
                            )}
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
                                {t('inventory.availability.all_states')}
                            </option>
                            <option value="in_stock">
                                {t('inventory.states.in_stock')}
                            </option>
                            <option value="out_of_stock">
                                {t('inventory.states.out_of_stock')}
                            </option>
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="min-w-0">
                                <div className="truncate font-medium">
                                    {row.name}
                                </div>
                                <div className="text-muted-foreground truncate text-xs">
                                    {row.sku} · {row.status_label}
                                </div>
                            </div>
                            {units(row)}
                            {preview(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Globe}
                            title={t(
                                filtered
                                    ? 'inventory.availability.no_matches'
                                    : 'inventory.availability.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'inventory.availability.no_matches_help'
                                    : 'inventory.availability.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

AdminAvailability.layout = {
    breadcrumbs: [
        {
            title: 'nav.availability',
            href: index(),
        },
    ],
};
