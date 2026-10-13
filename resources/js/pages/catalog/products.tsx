import { Head, Link } from '@inertiajs/react';
import { ImageOff, Package, Search } from 'lucide-react';
import { useState } from 'react';
import TablePagination from '@/components/data-table/table-pagination';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import {
    index,
    mine as myProducts,
    show as productShow,
} from '@/routes/catalog';
import { show as wholesaleShow } from '@/routes/catalog/wholesale';
import type { Paginator, PartnerProductCard, SelectOption } from '@/types';

type Props = {
    facility_allowed: boolean;
    products: Paginator<PartnerProductCard>;
    filters: {
        search: string;
        category: string | null;
        brand: string | null;
        stock?: 'in_stock' | 'out_of_stock' | null;
        price_min?: string | null;
        price_max?: string | null;
        scope?: 'all' | 'mine';
    };
    can: { wholesale: boolean; filter_wholesale: boolean };
    options: { categories: SelectOption[]; brands: SelectOption[] };
};

const fieldClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * The Partner's one Products workspace.
 *
 * Every product the account may use appears once, saying whether it can be
 * bought wholesale, sold through Partner Selling, or both. Eligibility, prices,
 * tiers and stock are the server's own answers; nothing here works out a price
 * or decides who may see a product.
 */
export default function PartnerProducts({
    facility_allowed,
    products,
    filters,
    can,
    options,
}: Props) {
    const { t } = useTranslation();
    const { search, setSearch, setFilter, goToPage } = useTableQuery({
        only: ['products', 'filters'],
    });
    const [priceMin, setPriceMin] = useState(filters.price_min ?? '');
    const [priceMax, setPriceMax] = useState(filters.price_max ?? '');

    const filtered =
        filters.search !== '' ||
        filters.category !== null ||
        filters.brand !== null ||
        Boolean(filters.stock) ||
        Boolean(filters.price_min) ||
        Boolean(filters.price_max);

    const applyPrice = (key: 'price_min' | 'price_max', value: string) =>
        setFilter(key, value.trim() === '' ? undefined : value.trim());

    if (!facility_allowed) {
        return (
            <>
                <Head title={t('partner_products.title')} />
                <PageContainer>
                    <PermissionDeniedState
                        title={t('partner_products.not_included_title')}
                        description={t('partner_products.not_included')}
                    />
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={t('partner_products.title')} />

            <PageContainer>
                <PageHeader
                    title={t('partner_products.title')}
                    description={t('partner_products.description')}
                />

                <div
                    role="group"
                    aria-label={t('partner_products.tabs.label')}
                    className="flex gap-2"
                >
                    {(['all', 'mine'] as const).map((scope) => (
                        <Button
                            key={scope}
                            asChild
                            size="sm"
                            variant={
                                (filters.scope ?? 'all') === scope
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            <Link
                                href={scope === 'mine' ? myProducts() : index()}
                                aria-current={
                                    (filters.scope ?? 'all') === scope
                                        ? 'page'
                                        : undefined
                                }
                                data-test={`products-scope-${scope}`}
                            >
                                {t(`partner_products.tabs.${scope}`)}
                            </Link>
                        </Button>
                    ))}
                </div>

                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div className="relative sm:col-span-2 xl:col-span-2">
                        <Search
                            className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                            aria-hidden="true"
                        />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('catalog.browse.search')}
                            aria-label={t('catalog.browse.search')}
                            className="pl-8"
                        />
                    </div>

                    <select
                        aria-label={t('catalog.browse.category')}
                        value={filters.category ?? ''}
                        onChange={(event) =>
                            setFilter(
                                'category',
                                event.target.value || undefined,
                            )
                        }
                        className={fieldClass}
                    >
                        <option value="">
                            {t('catalog.browse.all_categories')}
                        </option>
                        {options.categories.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label={t('catalog.browse.brand')}
                        value={filters.brand ?? ''}
                        onChange={(event) =>
                            setFilter('brand', event.target.value || undefined)
                        }
                        className={fieldClass}
                    >
                        <option value="">
                            {t('catalog.browse.all_brands')}
                        </option>
                        {options.brands.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    {can.filter_wholesale && (
                        <>
                            <select
                                aria-label={t('catalog.browse.stock')}
                                value={filters.stock ?? ''}
                                onChange={(event) =>
                                    setFilter(
                                        'stock',
                                        event.target.value || undefined,
                                    )
                                }
                                className={fieldClass}
                            >
                                <option value="">
                                    {t('catalog.browse.all_stock')}
                                </option>
                                <option value="in_stock">
                                    {t('catalog.browse.in_stock')}
                                </option>
                                <option value="out_of_stock">
                                    {t('catalog.browse.out_of_stock')}
                                </option>
                            </select>

                            <div className="grid grid-cols-2 gap-2">
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    value={priceMin}
                                    onChange={(event) =>
                                        setPriceMin(event.target.value)
                                    }
                                    onBlur={() =>
                                        applyPrice('price_min', priceMin)
                                    }
                                    onKeyDown={(event) =>
                                        event.key === 'Enter' &&
                                        applyPrice('price_min', priceMin)
                                    }
                                    placeholder={t('catalog.browse.price_min')}
                                    aria-label={t('catalog.browse.price_min')}
                                    className={fieldClass}
                                />
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    value={priceMax}
                                    onChange={(event) =>
                                        setPriceMax(event.target.value)
                                    }
                                    onBlur={() =>
                                        applyPrice('price_max', priceMax)
                                    }
                                    onKeyDown={(event) =>
                                        event.key === 'Enter' &&
                                        applyPrice('price_max', priceMax)
                                    }
                                    placeholder={t('catalog.browse.price_max')}
                                    aria-label={t('catalog.browse.price_max')}
                                    className={fieldClass}
                                />
                            </div>
                        </>
                    )}
                </div>

                {products.data.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title={t(
                            filtered
                                ? 'catalog.browse.no_matches'
                                : filters.scope === 'mine'
                                  ? 'partner_products.mine_empty_title'
                                  : 'catalog.browse.empty',
                        )}
                        description={t(
                            filtered
                                ? 'catalog.browse.no_matches_help'
                                : filters.scope === 'mine'
                                  ? 'partner_products.mine_empty_help'
                                  : 'catalog.browse.empty_help',
                        )}
                    />
                ) : (
                    <div className="space-y-4">
                        <ul
                            className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
                            data-test="partner-products"
                        >
                            {products.data.map((product) => (
                                <li key={product.slug} className="flex">
                                    <ProductTile
                                        product={product}
                                        scope={filters.scope ?? 'all'}
                                    />
                                </li>
                            ))}
                        </ul>
                        <TablePagination
                            paginator={products}
                            onPageChange={goToPage}
                        />
                    </div>
                )}
            </PageContainer>
        </>
    );
}

export function ProductTile({
    product,
    scope = 'all',
}: {
    product: PartnerProductCard;
    scope?: 'all' | 'mine';
}) {
    const { t } = useTranslation();
    // Bought already: buying it again is offered under My Products, not here.
    const reorderElsewhere = product.purchased === true && scope !== 'mine';
    const wholesale = product.wholesale;
    const selling = product.partner_selling;

    return (
        <Card
            className="flex w-full flex-col gap-0 overflow-hidden py-0"
            data-test="partner-product-card"
        >
            <div className="bg-muted flex aspect-[4/3] items-center justify-center overflow-hidden">
                {product.image ? (
                    <img
                        src={product.image.url}
                        alt={product.image.alt ?? product.name}
                        className="size-full object-cover"
                        loading="lazy"
                    />
                ) : (
                    <span className="text-muted-foreground flex flex-col items-center gap-1 text-xs">
                        <ImageOff className="size-6" aria-hidden="true" />
                        {t('catalog.browse.no_image')}
                    </span>
                )}
            </div>

            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex flex-wrap gap-1.5">
                    {product.modes.wholesale && (
                        <StatusPill
                            tone="info"
                            label={t('partner_products.modes.wholesale')}
                        />
                    )}
                    {product.modes.partner_selling && (
                        <StatusPill
                            tone="success"
                            label={t('partner_products.modes.partner_selling')}
                        />
                    )}
                    {wholesale && (
                        <StatusPill
                            tone={wholesale.in_stock ? 'success' : 'warning'}
                            label={t(
                                wholesale.in_stock
                                    ? 'catalog.browse.in_stock'
                                    : 'catalog.browse.out_of_stock',
                            )}
                        />
                    )}
                </div>

                <div className="min-w-0 space-y-0.5">
                    <h3 className="line-clamp-2 leading-snug font-medium">
                        <Link
                            href={productShow(product.slug)}
                            className="focus-visible:ring-ring rounded-sm hover:underline focus-visible:ring-2 focus-visible:outline-none"
                        >
                            {product.name}
                        </Link>
                    </h3>
                    <p className="text-muted-foreground truncate text-xs">
                        {t('partner_products.details.bpc')} {product.bpc}
                        {' · '}
                        {product.category}
                        {product.brand ? ` · ${product.brand}` : ''}
                    </p>
                </div>

                <dl className="space-y-1.5 text-sm">
                    {wholesale && (
                        <PriceRow
                            label={t('partner_products.price.wholesale')}
                            amount={wholesale.price}
                        />
                    )}
                    {selling && (
                        <>
                            <PriceRow
                                label={t('partner_products.price.suggested')}
                                amount={selling.suggested_selling_price}
                            />
                            <div className="text-muted-foreground flex justify-between gap-3 text-xs">
                                <dt>{t('partner_products.price.minimum')}</dt>
                                <dd>
                                    {selling.minimum_selling_price ? (
                                        <MoneyAmount
                                            amount={
                                                selling.minimum_selling_price
                                            }
                                            size="small"
                                        />
                                    ) : (
                                        t('partner_products.price.not_set')
                                    )}
                                </dd>
                            </div>
                            <div className="text-muted-foreground flex justify-between gap-3 text-xs">
                                <dt>{t('partner_products.price.maximum')}</dt>
                                <dd>
                                    {selling.maximum_selling_price ? (
                                        <MoneyAmount
                                            amount={
                                                selling.maximum_selling_price
                                            }
                                            size="small"
                                        />
                                    ) : (
                                        t('partner_products.price.not_set')
                                    )}
                                </dd>
                            </div>
                        </>
                    )}
                </dl>

                <details className="text-muted-foreground group border-t pt-2 text-xs">
                    <summary className="focus-visible:ring-ring cursor-pointer rounded-sm select-none focus-visible:ring-2 focus-visible:outline-none">
                        {t('partner_products.details.more')}
                    </summary>
                    <dl className="mt-2 space-y-1">
                        {wholesale && (
                            <>
                                <DetailRow
                                    label={t(
                                        'partner_products.details.min_order',
                                    )}
                                    value={t('partner_products.details.units', {
                                        count: wholesale.min_order_quantity,
                                    })}
                                />
                                {wholesale.max_order_quantity !== null && (
                                    <DetailRow
                                        label={t(
                                            'partner_products.details.max_order',
                                        )}
                                        value={t(
                                            'partner_products.details.units',
                                            {
                                                count: wholesale.max_order_quantity,
                                            },
                                        )}
                                    />
                                )}
                                {wholesale.tiers.length > 0 && (
                                    <div>
                                        <dt>
                                            {t(
                                                'partner_products.details.tiers',
                                            )}
                                        </dt>
                                        <dd>
                                            <ul className="mt-0.5 space-y-0.5">
                                                {wholesale.tiers.map((tier) => (
                                                    <li key={tier.min_quantity}>
                                                        {t(
                                                            'partner_products.details.tier',
                                                            {
                                                                count: tier.min_quantity,
                                                                amount: tier
                                                                    .unit_price
                                                                    .formatted,
                                                            },
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        </dd>
                                    </div>
                                )}
                            </>
                        )}
                        {product.variant_count > 0 && (
                            <DetailRow
                                label={t('partner_products.details.variations')}
                                value={String(product.variant_count)}
                            />
                        )}
                        <DetailRow
                            label={t('partner_products.details.package')}
                            value={t(
                                product.package_scope === 'selected'
                                    ? 'partner_products.details.package_selected'
                                    : 'partner_products.details.package_all',
                            )}
                        />
                    </dl>
                </details>

                <div className="mt-auto flex flex-wrap gap-2 pt-1">
                    {wholesale && !reorderElsewhere && (
                        <Button asChild size="sm">
                            <Link href={wholesaleShow(product.slug)}>
                                {scope === 'mine'
                                    ? t('partner_products.actions.reorder')
                                    : t('partner_products.actions.order')}
                            </Link>
                        </Button>
                    )}
                    {reorderElsewhere && (
                        <Button asChild size="sm" variant="outline">
                            <Link href={myProducts()}>
                                {t('partner_products.actions.in_my_products')}
                            </Link>
                        </Button>
                    )}
                    <Button asChild size="sm" variant="ghost">
                        <Link href={productShow(product.slug)}>
                            {t('partner_products.actions.view')}
                        </Link>
                    </Button>
                </div>
            </div>
        </Card>
    );
}

function PriceRow({ label, amount }: { label: string; amount: Money | null }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd>
                {amount ? (
                    <MoneyAmount amount={amount} className="font-semibold" />
                ) : (
                    <span className="text-muted-foreground text-xs">—</span>
                )}
            </dd>
        </div>
    );
}

function DetailRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3">
            <dt>{label}</dt>
            <dd className="text-foreground">{value}</dd>
        </div>
    );
}
