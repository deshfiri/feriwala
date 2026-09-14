import { Head, Link } from '@inertiajs/react';
import { ImageOff, Search, ShoppingCart, Store } from 'lucide-react';
import { useState } from 'react';
import TablePagination from '@/components/data-table/table-pagination';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import EmptyState from '@/components/states/empty-state';
import PermissionDeniedState from '@/components/states/permission-denied-state';
import StatusPill from '@/components/status-pill';
import { Input } from '@/components/ui/input';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/hooks/use-translation';
import { show as dropshippingShow } from '@/routes/catalog/dropshipping';
import { show as wholesaleShow } from '@/routes/catalog/wholesale';
import type {
    BrowseCard,
    Paginator,
    SalesChannelName,
    SelectOption,
} from '@/types';

type Props = {
    channel: SalesChannelName;
    facility_allowed: boolean;
    products: Paginator<BrowseCard>;
    filters: {
        search: string;
        category: string | null;
        brand: string | null;
        stock?: 'in_stock' | 'out_of_stock' | null;
        price_min?: string | null;
        price_max?: string | null;
    };
    options: { categories: SelectOption[]; brands: SelectOption[] };
};

const priceClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none sm:w-36';

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none sm:w-56';

/**
 * The central catalogue as a business account browses it (§10, §13).
 *
 * One page shape, two separate screens: the wholesale and dropshipping routes
 * each render this with only their own products and their own prices. The list
 * is exactly what the server says this account may see on this channel —
 * searching, filtering and paging are database queries (§39), and nothing here
 * decides eligibility or works out a price.
 */
export default function BrowseCatalogue({
    channel,
    facility_allowed,
    products,
    filters,
    options,
}: Props) {
    const { t } = useTranslation();
    const { search, setSearch, setFilter, goToPage } = useTableQuery({
        only: ['products', 'filters'],
    });

    const title = t(`catalog.browse.${channel}_title`);
    const wholesale = channel === 'wholesale';
    const [priceMin, setPriceMin] = useState(filters.price_min ?? '');
    const [priceMax, setPriceMax] = useState(filters.price_max ?? '');
    const filtered =
        filters.search !== '' ||
        filters.category !== null ||
        filters.brand !== null ||
        Boolean(filters.stock) ||
        Boolean(filters.price_min) ||
        Boolean(filters.price_max);

    // The server decides whether a bound is a sensible amount; a mistyped one
    // comes back cleared.
    const applyPrice = (key: 'price_min' | 'price_max', value: string) =>
        setFilter(key, value.trim() === '' ? undefined : value.trim());

    if (!facility_allowed) {
        return (
            <>
                <Head title={title} />
                <PageContainer>
                    <PermissionDeniedState
                        title={t('catalog.browse.not_included_title')}
                        description={t(
                            `catalog.browse.${channel}_not_included`,
                        )}
                    />
                </PageContainer>
            </>
        );
    }

    return (
        <>
            <Head title={title} />

            <PageContainer>
                <PageHeader
                    title={title}
                    description={t(`catalog.browse.${channel}_description`)}
                />

                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative sm:w-72">
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
                        className={selectClass}
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
                        className={selectClass}
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

                    {wholesale && (
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
                                className={selectClass}
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

                            <input
                                type="text"
                                inputMode="decimal"
                                value={priceMin}
                                onChange={(event) =>
                                    setPriceMin(event.target.value)
                                }
                                onBlur={() => applyPrice('price_min', priceMin)}
                                onKeyDown={(event) =>
                                    event.key === 'Enter' &&
                                    applyPrice('price_min', priceMin)
                                }
                                placeholder={t('catalog.browse.price_min')}
                                aria-label={t('catalog.browse.price_min')}
                                className={priceClass}
                            />

                            <input
                                type="text"
                                inputMode="decimal"
                                value={priceMax}
                                onChange={(event) =>
                                    setPriceMax(event.target.value)
                                }
                                onBlur={() => applyPrice('price_max', priceMax)}
                                onKeyDown={(event) =>
                                    event.key === 'Enter' &&
                                    applyPrice('price_max', priceMax)
                                }
                                placeholder={t('catalog.browse.price_max')}
                                aria-label={t('catalog.browse.price_max')}
                                className={priceClass}
                            />
                        </>
                    )}
                </div>

                {products.data.length === 0 ? (
                    <EmptyState
                        icon={channel === 'wholesale' ? ShoppingCart : Store}
                        title={t(
                            filtered
                                ? 'catalog.browse.no_matches'
                                : 'catalog.browse.empty',
                        )}
                        description={t(
                            filtered
                                ? 'catalog.browse.no_matches_help'
                                : 'catalog.browse.empty_help',
                        )}
                    />
                ) : (
                    <div className="bg-card overflow-hidden rounded-xl border">
                        <ul className="grid gap-px sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            {products.data.map((product) => (
                                <li
                                    key={product.slug}
                                    className="bg-card border-border border-b sm:border-r"
                                >
                                    <ProductCard
                                        channel={channel}
                                        product={product}
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

export function ProductCard({
    channel,
    product,
}: {
    channel: SalesChannelName;
    product: BrowseCard;
}) {
    const { t } = useTranslation();
    const href =
        channel === 'wholesale'
            ? wholesaleShow(product.slug)
            : dropshippingShow(product.slug);

    return (
        <Link
            href={href}
            className="hover:bg-muted/40 focus-visible:ring-ring flex h-full flex-col gap-3 p-4 focus-visible:ring-2 focus-visible:outline-none"
        >
            <div className="bg-muted flex aspect-square items-center justify-center overflow-hidden rounded-lg">
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

            <div className="min-w-0 space-y-0.5">
                {product.is_featured && (
                    <StatusPill
                        tone="info"
                        label={t('catalog.browse.featured')}
                    />
                )}
                <p className="line-clamp-2 font-medium">{product.name}</p>
                <p className="text-muted-foreground truncate text-xs">
                    {product.category}
                    {product.brand ? ` · ${product.brand}` : ''}
                </p>
            </div>

            <div className="mt-auto space-y-1 text-sm">
                {channel === 'wholesale' && product.wholesale_price ? (
                    <>
                        <StatusPill
                            tone={product.in_stock ? 'success' : 'warning'}
                            label={t(
                                product.in_stock
                                    ? 'catalog.browse.in_stock'
                                    : 'catalog.browse.out_of_stock',
                            )}
                        />
                        <p>
                            <MoneyAmount
                                amount={product.wholesale_price}
                                className="font-semibold"
                            />{' '}
                            <span className="text-muted-foreground text-xs">
                                {t('catalog.browse.wholesale_price')}
                            </span>
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {t('catalog.browse.min_order', {
                                count: product.min_order_quantity ?? 1,
                            })}
                        </p>
                        {product.has_quantity_pricing && (
                            <p className="text-xs">
                                {t('catalog.browse.quantity_pricing')}
                            </p>
                        )}
                    </>
                ) : (
                    <SellingGuidanceLine product={product} />
                )}
            </div>
        </Link>
    );
}

function SellingGuidanceLine({ product }: { product: BrowseCard }) {
    const { t } = useTranslation();
    const min = product.minimum_selling_price;
    const max = product.maximum_selling_price;

    return (
        <>
            {product.suggested_selling_price ? (
                <p>
                    <MoneyAmount
                        amount={product.suggested_selling_price}
                        className="font-semibold"
                    />{' '}
                    <span className="text-muted-foreground text-xs">
                        {t('catalog.browse.suggested_price')}
                    </span>
                </p>
            ) : null}
            <p className="text-muted-foreground text-xs">
                {min && max
                    ? t('catalog.browse.price_range', {
                          min: min.formatted,
                          max: max.formatted,
                      })
                    : min
                      ? t('catalog.browse.price_from', { min: min.formatted })
                      : max
                        ? t('catalog.browse.price_up_to', {
                              max: max.formatted,
                          })
                        : product.suggested_selling_price
                          ? ''
                          : t('catalog.browse.no_price_guidance')}
            </p>
        </>
    );
}
