import { Head, Link } from '@inertiajs/react';
import { Plus, ShoppingBag } from 'lucide-react';
import DataTable from '@/components/data-table/data-table';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { create, edit } from '@/routes/admin/catalog/products';
import type { CatalogAbilities, Column, Paginator, ProductRow } from '@/types';

type Props = {
    products: Paginator<ProductRow>;
    can: CatalogAbilities;
};

/**
 * The central catalogue (§11).
 *
 * Server-paginated and searched in the database (§39): a catalogue grows to
 * thousands of products, and a browser can only filter what it was sent. The
 * wholesale price is the server's own rendering — the page formats no money.
 */
export default function AdminProducts({ products, can }: Props) {
    const { t } = useTranslation();
    const { search } = useTableQuery({ only: ['products'] });

    const columns: Column<ProductRow>[] = [
        {
            key: 'name',
            header: t('catalog.products.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <Link
                        href={edit(row.id)}
                        className="block truncate font-medium hover:underline"
                    >
                        {row.name}
                    </Link>
                    <span className="text-muted-foreground font-mono text-xs">
                        {row.sku}
                    </span>
                </div>
            ),
        },
        {
            key: 'category',
            header: t('catalog.products.columns.category'),
            priority: 'secondary',
            cell: (row) => row.category,
        },
        {
            key: 'brand',
            header: t('catalog.products.columns.brand'),
            priority: 'secondary',
            cell: (row) => row.brand ?? '—',
        },
        {
            key: 'wholesale_price',
            header: t('catalog.products.columns.wholesale_price'),
            align: 'end',
            cell: (row) => <MoneyAmount amount={row.wholesale_price} />,
        },
        {
            key: 'status',
            header: t('catalog.products.columns.status'),
            cell: (row) => <ProductStatusPill status={row.status} />,
        },
    ];

    return (
        <>
            <Head title={t('catalog.products.title')} />

            <PageContainer>
                <PageHeader
                    title={t('catalog.products.title')}
                    description={t('catalog.products.description')}
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.products.create')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={products}
                    rowKey={(row) => row.id}
                    caption={t('catalog.products.caption')}
                    searchPlaceholder={t('catalog.products.search')}
                    onlyReload={['products']}
                    renderCard={(row) => (
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0 space-y-0.5">
                                <Link
                                    href={edit(row.id)}
                                    className="block truncate font-medium hover:underline"
                                >
                                    {row.name}
                                </Link>
                                <p className="text-muted-foreground font-mono text-xs">
                                    {row.sku}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {row.category}
                                    {row.brand ? ` · ${row.brand}` : ''}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1">
                                <MoneyAmount amount={row.wholesale_price} />
                                <ProductStatusPill status={row.status} />
                            </div>
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={ShoppingBag}
                            title={t(
                                search !== ''
                                    ? 'catalog.products.no_matches'
                                    : 'catalog.products.empty',
                            )}
                            description={t(
                                search !== ''
                                    ? 'catalog.products.no_matches_help'
                                    : 'catalog.products.empty_help',
                            )}
                        />
                    }
                />
            </PageContainer>
        </>
    );
}

/**
 * A product's status, always in words (§33.9).
 */
export function ProductStatusPill({ status }: { status: string }) {
    const { t } = useTranslation();

    return (
        <StatusPill
            tone="neutral"
            label={t(`catalog.products.status.${status}`)}
        />
    );
}
