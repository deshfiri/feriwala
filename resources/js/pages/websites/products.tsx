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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteProductController from '@/actions/App/Http/Controllers/Erp/WebsiteProductController';
import { index as dropshippingCatalogue } from '@/routes/catalog/dropshipping';
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
                        onClick={() =>
                            setEditing(editing === row.id ? null : row.id)
                        }
                    >
                        {t('website.products.edit')}
                    </Button>
                </div>
            ),
        },
    ];

    const editor = (row: WebsiteSelection) => (
        <Form
            {...WebsiteProductController.update.form({
                website: website.id,
                selection: row.id,
            })}
            options={{ preserveScroll: true }}
            className="bg-muted/40 mt-3 space-y-3 rounded-lg p-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`price-${row.id}`}>
                                {t('website.products.price_minor')}
                            </Label>
                            <Input
                                id={`price-${row.id}`}
                                name="price"
                                type="number"
                                min={0}
                                step={1}
                                defaultValue={row.price?.minor_units ?? ''}
                                disabled={
                                    !row.terms.allows_user_pricing ||
                                    row.terms.locked_fields.includes('price')
                                }
                            />
                            <InputError message={errors.price} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`promo-${row.id}`}>
                                {t('website.products.promotional_price_minor')}
                            </Label>
                            <Input
                                id={`promo-${row.id}`}
                                name="promotional_price"
                                type="number"
                                min={0}
                                step={1}
                                defaultValue={
                                    row.promotional_price?.minor_units ?? ''
                                }
                                disabled={row.terms.locked_fields.includes(
                                    'promotional_price',
                                )}
                            />
                            <InputError message={errors.promotional_price} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`title-${row.id}`}>
                                {t('website.products.promo_title')}
                            </Label>
                            <Input
                                id={`title-${row.id}`}
                                name="promo_title"
                                defaultValue={row.promo_title ?? ''}
                                maxLength={120}
                                disabled={row.terms.locked_fields.includes(
                                    'promo_title',
                                )}
                            />
                            <InputError message={errors.promo_title} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`category-${row.id}`}>
                                {t('website.products.category')}
                            </Label>
                            <select
                                id={`category-${row.id}`}
                                name="website_category_id"
                                defaultValue={row.category?.id ?? ''}
                                className={selectClass}
                                disabled={row.terms.locked_fields.includes(
                                    'website_category',
                                )}
                            >
                                <option value="">
                                    {t('website.products.uncategorised')}
                                </option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.website_category_id} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor={`order-${row.id}`}>
                                {t('website.products.display_order')}
                            </Label>
                            <Input
                                id={`order-${row.id}`}
                                name="display_order"
                                type="number"
                                min={0}
                                defaultValue={row.display_order}
                                disabled={row.terms.locked_fields.includes(
                                    'display_order',
                                )}
                            />
                            <InputError message={errors.display_order} />
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_featured"
                                value="1"
                                defaultChecked={row.is_featured}
                                disabled={row.terms.locked_fields.includes(
                                    'is_featured',
                                )}
                            />
                            {t('website.products.featured')}
                        </label>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor={`description-${row.id}`}>
                            {t('website.products.marketing_description')}
                        </Label>
                        <textarea
                            id={`description-${row.id}`}
                            name="marketing_description"
                            rows={3}
                            maxLength={1000}
                            defaultValue={row.marketing_description ?? ''}
                            className="border-input bg-background w-full rounded-lg border px-3 py-2 text-sm"
                            disabled={row.terms.locked_fields.includes(
                                'marketing_description',
                            )}
                        />
                        <InputError message={errors.marketing_description} />
                    </div>

                    <Button type="submit" size="sm" disabled={processing}>
                        {processing && <Spinner />}
                        {t('website.products.save')}
                    </Button>
                </>
            )}
        </Form>
    );

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

                {selections.data
                    .filter((row) => row.id === editing)
                    .map((row) => (
                        <div key={row.id}>{editor(row)}</div>
                    ))}
            </PageContainer>
        </>
    );
}

WebsiteProducts.layout = {
    breadcrumbs: [
        {
            title: 'Websites',
            href: websitesIndex(),
        },
    ],
};
