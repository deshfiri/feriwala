import { Form, Head, Link } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import { useState } from 'react';
import DataTable from '@/components/data-table/data-table';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import WebsiteSelectionDialog from '@/components/website-selection-dialog';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteProductController from '@/actions/App/Http/Controllers/Erp/WebsiteProductController';
import { index as dropshippingCatalogue } from '@/routes/catalog';
import { index as websiteCategories } from '@/routes/websites/categories';
import { index as websitesIndex, show as websiteShow } from '@/routes/websites';
import type { Column, Paginator } from '@/types';
import type { WebsiteSelection, WebsiteSummary } from '@/types/website';

type Props = {
    website: WebsiteSummary;
    selections: Paginator<WebsiteSelection>;
    filters: {
        search: string | null;
        status: string | null;
        category: string | null;
    };
    categories: {
        id: string;
        name: string;
        is_active: boolean;
        products_count: number;
    }[];
    statuses: { value: string; label: string }[];
    publishing: {
        limit: number | null;
        used: number;
        remaining: number | null;
    };
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-lg border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

const tones: Record<string, 'success' | 'warning' | 'neutral'> = {
    published: 'success',
    selected: 'warning',
    unpublished: 'neutral',
};

const syncTones: Record<string, 'success' | 'warning' | 'danger'> = {
    synced: 'success',
    pending: 'warning',
    failed: 'danger',
};

/**
 * What one storefront sells (§15, §15.1, P5-2–P5-7).
 *
 * Products are **chosen** from the Feriwala catalogue, never written here
 * (§12, §16.3). Each row shows what the shop charges, where the storefront's
 * copy stands, and — from the server — the bounds the partner may price
 * within; every figure is checked again when it is saved.
 */
export default function WebsiteProducts({
    website,
    selections,
    filters,
    categories,
    statuses,
    publishing,
}: Props) {
    const { t, locale } = useTranslation();
    const { setFilter } = useTableQuery({ only: ['selections', 'filters'] });
    const [editing, setEditing] = useState<string | null>(null);
    // Looked up by id on every render, so a row that has gone from the page
    // (another page, a filter) takes its dialog with it.
    const editingRow = selections.data.find((row) => row.id === editing);

    const allowance =
        publishing.limit === null
            ? t('website.products.published_unlimited', {
                  used: String(publishing.used),
              })
            : t('website.products.published_of', {
                  used: String(publishing.used),
                  limit: String(publishing.limit),
              });

    const priceCell = (row: WebsiteSelection) => (
        <div className="space-y-1">
            {row.price ? (
                <MoneyAmount amount={row.price} />
            ) : (
                <span className="text-warning-foreground text-xs font-medium">
                    {t('website.products.no_price')}
                </span>
            )}

            {row.promotional_price && (
                <div className="text-muted-foreground text-xs">
                    {t('website.products.promotion')}:{' '}
                    {row.promotional_price.formatted}
                    {!row.promotion_applies &&
                        ` (${
                            row.promotion_ends_at &&
                            new Date(row.promotion_ends_at) <= new Date()
                                ? t('website.products.promotion_ended')
                                : t('website.products.promotion_scheduled')
                        })`}
                </div>
            )}

            {(row.promotion_starts_at || row.promotion_ends_at) && (
                <div className="text-muted-foreground text-xs">
                    {row.promotion_starts_at
                        ? new Date(row.promotion_starts_at).toLocaleString(
                              locale,
                          )
                        : '…'}{' '}
                    –{' '}
                    {row.promotion_ends_at
                        ? new Date(row.promotion_ends_at).toLocaleString(locale)
                        : '…'}
                </div>
            )}

            {row.terms.minimum && (
                <div className="text-muted-foreground text-xs">
                    {t('website.products.bounds', {
                        minimum: row.terms.minimum.formatted,
                        maximum: row.terms.maximum?.formatted ?? '—',
                    })}
                </div>
            )}
        </div>
    );

    const columns: Column<WebsiteSelection>[] = [
        {
            key: 'product',
            header: t('website.products.columns.product'),
            cell: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm font-medium">
                        {row.product.name}
                    </div>
                    <div className="text-muted-foreground truncate font-mono text-xs">
                        {row.product.sku}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: t('website.products.columns.status'),
            cell: (row) => (
                <div className="space-y-1">
                    <StatusPill
                        tone={tones[row.status] ?? 'neutral'}
                        label={row.status_label}
                    />
                    <StatusPill
                        tone={syncTones[row.sync_status] ?? 'neutral'}
                        label={row.sync_label}
                    />
                    {row.last_synced_at && (
                        <div className="text-muted-foreground text-xs">
                            {new Date(row.last_synced_at).toLocaleString(
                                locale,
                            )}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'price',
            header: t('website.products.columns.price'),
            align: 'end',
            cell: priceCell,
        },
        {
            key: 'placement',
            header: t('website.products.columns.placement'),
            priority: 'secondary',
            cell: (row) => (
                <div className="text-muted-foreground space-y-1 text-xs">
                    <div>
                        {row.category?.name ??
                            t('website.products.uncategorised')}
                    </div>
                    <div>
                        {t('website.products.order', {
                            order: String(row.display_order),
                        })}
                    </div>
                    {row.is_featured && (
                        <div className="text-foreground font-medium">
                            {t('website.products.featured')}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: t('website.products.columns.actions'),
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <Form
                        {...WebsiteProductController.updatePublication.form({
                            website: website.id,
                            selection: row.id,
                        })}
                        options={{ preserveScroll: true }}
                        transform={(data) => ({
                            ...data,
                            published: row.status === 'published' ? 0 : 1,
                        })}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-1">
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant={
                                        row.status === 'published'
                                            ? 'outline'
                                            : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {row.status === 'published'
                                        ? t('website.products.unpublish')
                                        : t('website.products.publish')}
                                </Button>
                                <InputError
                                    message={errors.product ?? errors.price}
                                />
                            </div>
                        )}
                    </Form>

                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(row.id)}
                    >
                        {t('website.products.edit')}
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('website.products.title')} />

            <PageContainer>
                <PageHeader
                    title={t('website.products.title')}
                    description={`${website.name} · ${allowance}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" asChild>
                                <Link href={websiteCategories(website.id)}>
                                    {t('website.products.categories')}
                                </Link>
                            </Button>
                            <Button size="sm" asChild>
                                <Link href={dropshippingCatalogue()}>
                                    {t('website.products.choose')}
                                </Link>
                            </Button>
                            <Button size="sm" variant="ghost" asChild>
                                <Link href={websiteShow(website.id)}>
                                    {website.name}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <DataTable
                    columns={columns}
                    paginator={selections}
                    rowKey={(row) => row.id}
                    caption={t('website.products.title')}
                    searchPlaceholder={t('website.products.search')}
                    onlyReload={['selections', 'filters']}
                    filters={
                        <select
                            aria-label={t('website.products.columns.status')}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                setFilter(
                                    'status',
                                    event.target.value || undefined,
                                )
                            }
                            className={selectClass}
                        >
                            <option value="">
                                {t('website.products.all_statuses')}
                            </option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    }
                    renderCard={(row) => (
                        <div className="space-y-2">
                            <div className="text-sm font-medium">
                                {row.product.name}
                            </div>
                            <StatusPill
                                tone={tones[row.status] ?? 'neutral'}
                                label={row.status_label}
                            />
                            {priceCell(row)}
                        </div>
                    )}
                    emptyState={
                        <EmptyState
                            icon={PackageSearch}
                            title={t('website.products.empty')}
                            description={t('website.products.empty_help')}
                            action={
                                <Button asChild>
                                    <Link href={dropshippingCatalogue()}>
                                        {t('website.products.choose')}
                                    </Link>
                                </Button>
                            }
                        />
                    }
                />

                {editingRow && (
                    <WebsiteSelectionDialog
                        // Keyed by the selection, so each opening starts from
                        // what is saved for that product alone.
                        key={editingRow.id}
                        websiteId={website.id}
                        row={editingRow}
                        categories={categories}
                        onClose={() => setEditing(null)}
                    />
                )}
            </PageContainer>
        </>
    );
}

WebsiteProducts.layout = {
    breadcrumbs: [
        {
            title: 'nav.websites',
            href: websitesIndex(),
        },
    ],
};
