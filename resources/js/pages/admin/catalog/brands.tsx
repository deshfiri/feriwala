import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Pencil,
    Plus,
    Power,
    ShoppingBag,
    Tags,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import BrandController from '@/actions/App/Http/Controllers/Admin/BrandController';
import DataTable from '@/components/data-table/data-table';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { index as productsIndex } from '@/routes/admin/catalog/products';
import type { Column, Paginator } from '@/types';
import BrandDialog from './brand-dialog';

export type BrandRow = {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    logo_url: string | null;
    logo_alt: string | null;
    is_active: boolean;
    sort_order: number;
    products_count: number;
};

export type CatalogImageLimits = {
    image_max_kb: number;
    image_types: string[];
};

type Props = {
    brands: Paginator<BrandRow>;
    filters: { status: 'active' | 'inactive' | null };
    can: { create: boolean; edit: boolean; delete: boolean };
    limits: CatalogImageLimits;
};

/**
 * Product brands (§11.3).
 *
 * A server-paginated table, unlike the category tree: brands are flat and a
 * catalogue can hold hundreds, so search and the status filter are database
 * queries rather than a browser sifting rows it was sent (§39).
 *
 * Switched-off brands stay listed, labelled in words rather than tinted, so
 * "why has that brand stopped showing" is answered on sight (§33.9).
 */
export default function AdminBrands({ brands, filters, can, limits }: Props) {
    const { t } = useTranslation();
    const { search, setFilter, sort } = useTableQuery({
        only: ['brands', 'filters'],
    });

    const [editing, setEditing] = useState<BrandRow | null>(null);
    const [creating, setCreating] = useState(false);

    const toggle = (row: BrandRow) => {
        if (
            row.is_active &&
            row.products_count > 0 &&
            !window.confirm(
                t('catalog.brands.disable_confirm', {
                    name: row.name,
                    count: row.products_count,
                }),
            )
        ) {
            return;
        }

        router.patch(
            BrandController.toggle.url(row.id),
            { is_active: !row.is_active },
            { preserveScroll: true },
        );
    };

    /*
     * Moving is offered only in the order partners see — no search, filter or
     * sort — because anything else hides which brand is really the neighbour.
     * The server moves by the whole order either way.
     */
    const inPartnerOrder = search === '' && filters.status === null && !sort;
    const firstOnPage = brands.data[0]?.id;
    const lastOnPage = brands.data.at(-1)?.id;

    const move = (row: BrandRow, direction: 'up' | 'down') =>
        router.patch(
            BrandController.move.url(row.id),
            { direction },
            { preserveScroll: true },
        );

    const remove = (row: BrandRow) => {
        if (!window.confirm(t('catalog.brands.delete_confirm'))) {
            return;
        }

        router.delete(BrandController.destroy.url(row.id), {
            preserveScroll: true,
        });
    };

    const status = (row: BrandRow) => (
        <StatusPill
            tone={row.is_active ? 'success' : 'neutral'}
            label={t(
                row.is_active
                    ? 'catalog.brands.state_live'
                    : 'catalog.brands.state_off',
            )}
        />
    );

    const logo = (row: BrandRow) =>
        row.logo_url ? (
            <img
                src={row.logo_url}
                alt={row.logo_alt ?? row.name}
                className="bg-muted size-9 shrink-0 rounded-md border object-contain"
            />
        ) : (
            <span
                className="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-md border"
                title={t('catalog.brands.no_logo')}
            >
                <Tags className="size-4" aria-hidden="true" />
                <span className="sr-only">{t('catalog.brands.no_logo')}</span>
            </span>
        );

    const actions = (row: BrandRow) => (
        <div className="flex flex-wrap items-center justify-end gap-1">
            <Button variant="ghost" size="sm" asChild>
                <Link href={productsIndex.url({ query: { brand: row.id } })}>
                    <ShoppingBag className="size-4" aria-hidden="true" />
                    <span className="sr-only sm:not-sr-only">
                        {t('catalog.brands.view_products')}
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        {row.products_count}
                    </span>
                </Link>
            </Button>
            {can.edit && inPartnerOrder && (
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={
                            brands.current_page === 1 && firstOnPage === row.id
                        }
                        onClick={() => move(row, 'up')}
                        aria-label={t('catalog.brands.move_up', {
                            name: row.name,
                        })}
                    >
                        <ArrowUp className="size-4" aria-hidden="true" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={
                            brands.current_page === brands.last_page &&
                            lastOnPage === row.id
                        }
                        onClick={() => move(row, 'down')}
                        aria-label={t('catalog.brands.move_down', {
                            name: row.name,
                        })}
                    >
                        <ArrowDown className="size-4" aria-hidden="true" />
                    </Button>
                </>
            )}
            {can.edit && (
                <>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(row)}
                    >
                        <Pencil className="size-4" aria-hidden="true" />
                        <span className="sr-only sm:not-sr-only">
                            {t('common.actions.edit')}
                        </span>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => toggle(row)}
                    >
                        <Power className="size-4" aria-hidden="true" />
                        <span className="sr-only sm:not-sr-only">
                            {t(
                                row.is_active
                                    ? 'catalog.brands.disable'
                                    : 'catalog.brands.enable',
                            )}
                        </span>
                    </Button>
                </>
            )}
            {can.delete && (
                <Button variant="ghost" size="sm" onClick={() => remove(row)}>
                    <Trash2 className="size-4" aria-hidden="true" />
                    <span className="sr-only">
                        {t('common.actions.delete')}
                    </span>
                </Button>
            )}
        </div>
    );

    const columns: Column<BrandRow>[] = [
        {
            key: 'name',
            header: t('catalog.brands.columns.brand'),
            sortable: true,
            cell: (row) => (
                <div className="flex min-w-0 items-center gap-3">
                    {logo(row)}
                    <span className="truncate font-medium">{row.name}</span>
                </div>
            ),
        },
        {
            key: 'slug',
            header: t('catalog.brands.columns.slug'),
            priority: 'secondary',
            cell: (row) => (
                <span className="text-muted-foreground font-mono text-xs">
                    /{row.slug}
                </span>
            ),
        },
        {
            key: 'status',
            header: t('catalog.brands.columns.status'),
            cell: status,
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            alwaysVisible: true,
            cell: actions,
        },
    ];

    const filtered = search !== '' || filters.status !== null;

    return (
        <>
            <Head title={t('catalog.brands.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.brands.title')}
                    description={`${t('catalog.brands.description')} ${can.edit ? t('catalog.brands.order_help') : ''}`.trim()}
                    actions={
                        can.create ? (
                            <Button onClick={() => setCreating(true)}>
                                <Plus className="size-4" aria-hidden="true" />
                                {t('catalog.brands.create')}
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={brands}
                    rowKey={(row) => row.id}
                    caption={t('catalog.brands.caption')}
                    searchPlaceholder={t('catalog.brands.search')}
                    onlyReload={['brands', 'filters']}
                    filters={
                        <select
                            aria-label={t('catalog.brands.filter_status')}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'status',
                                    event.target.value || undefined,
                                )
                            }
                            className="border-input bg-background focus-visible:ring-ring h-8 rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <option value="">
                                {t('catalog.brands.filter_all')}
                            </option>
                            <option value="active">
                                {t('catalog.brands.state_live')}
                            </option>
                            <option value="inactive">
                                {t('catalog.brands.state_off')}
                            </option>
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex min-w-0 items-center gap-3">
                                    {logo(row)}
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {row.name}
                                        </p>
                                        <p className="text-muted-foreground truncate font-mono text-xs">
                                            /{row.slug}
                                        </p>
                                    </div>
                                </div>
                                {status(row)}
                            </div>
                            {actions(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={Tags}
                            title={t(
                                filtered
                                    ? 'catalog.brands.no_matches'
                                    : 'catalog.brands.empty',
                            )}
                            description={t(
                                filtered
                                    ? 'catalog.brands.no_matches_help'
                                    : can.create
                                      ? 'catalog.brands.empty_help'
                                      : 'catalog.brands.empty_help_read_only',
                            )}
                        />
                    }
                />
            </PageContainer>

            <BrandDialog
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                brand={editing}
                limits={limits}
            />
        </>
    );
}
