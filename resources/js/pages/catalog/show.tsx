import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ImageOff } from 'lucide-react';
import { useState } from 'react';
import MoneyAmount from '@/components/money-amount';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { useTranslation } from '@/hooks/use-translation';
import { index as dropshippingIndex } from '@/routes/catalog/dropshipping';
import { index as wholesaleIndex } from '@/routes/catalog/wholesale';
import type { BrowseCard, BrowseDetail, SalesChannelName } from '@/types';
import { ProductCard } from './browse';

type Props = {
    channel: SalesChannelName;
    product: BrowseDetail;
    /** Only the recommendations this account may itself open on this channel. */
    related: BrowseCard[];
};

/**
 * One product, as a business account sees it on one channel (§13).
 *
 * The server only renders this when the account is eligible for the product on
 * this channel; anybody else gets a 404. Every figure is the server's own —
 * quantity pricing is resolved at each band's starting quantity before it
 * arrives, so a band the base price has since undercut shows what is charged.
 */
export default function CatalogueProduct({ channel, product, related }: Props) {
    const { t } = useTranslation();
    const [active, setActive] = useState(0);

    const current = product.media[active];

    return (
        <>
            <Head title={product.name} />

            <PageContainer>
                <Link
                    href={
                        channel === 'wholesale'
                            ? wholesaleIndex()
                            : dropshippingIndex()
                    }
                    className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-sm"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {t(`catalog.browse.back_${channel}`)}
                </Link>

                <PageHeader
                    title={product.name}
                    description={`${product.category}${product.brand ? ` · ${product.brand}` : ''}`}
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="space-y-3">
                        <div className="bg-muted flex aspect-square items-center justify-center overflow-hidden rounded-xl border">
                            {current ? (
                                current.type === 'image' ? (
                                    <img
                                        src={current.url}
                                        alt={current.alt ?? product.name}
                                        className="size-full object-contain"
                                    />
                                ) : (
                                    <video
                                        src={current.url}
                                        controls
                                        preload="metadata"
                                        className="size-full"
                                        aria-label={current.alt ?? product.name}
                                    />
                                )
                            ) : (
                                <span className="text-muted-foreground flex flex-col items-center gap-1 text-sm">
                                    <ImageOff
                                        className="size-8"
                                        aria-hidden="true"
                                    />
                                    {t('catalog.browse.no_image')}
                                </span>
                            )}
                        </div>

                        {product.media.length > 1 && (
                            <ul className="flex flex-wrap gap-2">
                                {product.media.map((item, index) => (
                                    <li key={item.url}>
                                        <button
                                            type="button"
                                            onClick={() => setActive(index)}
                                            aria-pressed={index === active}
                                            aria-label={
                                                item.alt ?? product.name
                                            }
                                            className="bg-muted focus-visible:ring-ring aria-pressed:border-foreground size-16 overflow-hidden rounded-md border-2 focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {item.type === 'image' ? (
                                                <img
                                                    src={item.url}
                                                    alt=""
                                                    className="size-full object-cover"
                                                />
                                            ) : (
                                                <span className="text-muted-foreground text-xs">
                                                    ▶
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="space-y-4">
                        <p className="text-muted-foreground font-mono text-xs">
                            {t('catalog.browse.sku', { sku: product.sku })}
                        </p>

                        {product.short_description && (
                            <p>{product.short_description}</p>
                        )}

                        {channel === 'wholesale' ? (
                            <WholesaleTerms product={product} />
                        ) : (
                            <SellingGuidance product={product} />
                        )}
                    </div>
                </div>

                {product.variants.length > 0 && (
                    <SectionCard title={t('catalog.browse.variations')}>
                        <ul className="divide-border divide-y">
                            {product.variants.map((variant) => (
                                <li
                                    key={variant.sku}
                                    className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <span>
                                            {variant.label}
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">
                                                {variant.sku}
                                            </span>
                                        </span>
                                        {variant.quantity_pricing &&
                                            variant.quantity_pricing.length >
                                                0 && (
                                                <p className="text-muted-foreground text-xs">
                                                    {variant.quantity_pricing
                                                        .map((band) =>
                                                            t(
                                                                'catalog.browse.band',
                                                                {
                                                                    count: band.min_quantity,
                                                                    amount: band
                                                                        .unit_price
                                                                        .formatted,
                                                                },
                                                            ),
                                                        )
                                                        .join(' · ')}
                                                </p>
                                            )}
                                    </div>
                                    <div className="space-y-1 text-right">
                                        {variant.wholesale_price && (
                                            <MoneyAmount
                                                amount={variant.wholesale_price}
                                            />
                                        )}
                                        {channel === 'wholesale' &&
                                            variant.in_stock !== undefined && (
                                                <StockLine
                                                    inStock={variant.in_stock}
                                                    available={
                                                        variant.available ?? 0
                                                    }
                                                />
                                            )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                {related.length > 0 && (
                    <SectionCard
                        title={t('catalog.browse.related')}
                        contentClassName="p-0"
                    >
                        <ul className="grid gap-px sm:grid-cols-2 lg:grid-cols-4">
                            {related.map((item) => (
                                <li
                                    key={item.slug}
                                    className="border-border border-b sm:border-r"
                                >
                                    <ProductCard
                                        channel={channel}
                                        product={item}
                                    />
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                {product.description && (
                    <SectionCard title={t('catalog.browse.details')}>
                        <p className="text-sm whitespace-pre-line">
                            {product.description}
                        </p>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}

function WholesaleTerms({ product }: { product: BrowseDetail }) {
    const { t } = useTranslation();

    return (
        <SectionCard>
            <div className="space-y-4">
                {product.wholesale_price && (
                    <div>
                        <p className="text-muted-foreground text-xs">
                            {t('catalog.browse.wholesale_price')}
                        </p>
                        <MoneyAmount
                            amount={product.wholesale_price}
                            size="large"
                        />
                    </div>
                )}

                {product.in_stock !== undefined && (
                    <div>
                        <p className="text-muted-foreground mb-1 text-xs">
                            {t('catalog.browse.stock')}
                        </p>
                        {product.available === null ? (
                            <StatusPill
                                tone={product.in_stock ? 'success' : 'warning'}
                                label={t(
                                    product.in_stock
                                        ? 'catalog.browse.in_stock'
                                        : 'catalog.browse.out_of_stock',
                                )}
                            />
                        ) : (
                            <StockLine
                                inStock={product.in_stock}
                                available={product.available ?? 0}
                            />
                        )}
                    </div>
                )}

                <div>
                    <p className="text-muted-foreground text-xs">
                        {t('catalog.browse.order_quantity')}
                    </p>
                    <p className="text-sm">
                        {product.max_order_quantity
                            ? t('catalog.browse.order_between', {
                                  min: product.min_order_quantity ?? 1,
                                  max: product.max_order_quantity,
                              })
                            : t('catalog.browse.order_at_least', {
                                  min: product.min_order_quantity ?? 1,
                              })}
                    </p>
                </div>

                {product.quantity_pricing &&
                    product.quantity_pricing.length > 0 && (
                        <div>
                            <p className="text-muted-foreground mb-1 text-xs">
                                {t('catalog.browse.quantity_pricing_title')}
                            </p>
                            <ul className="divide-border divide-y rounded-md border">
                                {product.quantity_pricing.map((band) => (
                                    <li
                                        key={band.min_quantity}
                                        className="flex justify-between gap-2 px-3 py-2 text-sm"
                                    >
                                        <span>
                                            {t('catalog.browse.from_units', {
                                                count: band.min_quantity,
                                            })}
                                        </span>
                                        <span>
                                            {t('catalog.browse.each', {
                                                amount: band.unit_price
                                                    .formatted,
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
            </div>
        </SectionCard>
    );
}

/** In stock with how many this account can order, or out of stock — in words. */
function StockLine({
    inStock,
    available,
}: {
    inStock: boolean;
    available: number;
}) {
    const { t } = useTranslation();

    return (
        <span className="inline-flex flex-wrap items-center justify-end gap-2">
            <StatusPill
                tone={inStock ? 'success' : 'warning'}
                label={t(
                    inStock
                        ? 'catalog.browse.in_stock'
                        : 'catalog.browse.out_of_stock',
                )}
            />
            {inStock && (
                <span className="text-muted-foreground text-xs tabular-nums">
                    {t('catalog.browse.available_to_order', {
                        count: available,
                    })}
                </span>
            )}
        </span>
    );
}

function SellingGuidance({ product }: { product: BrowseDetail }) {
    const { t } = useTranslation();

    const rows = [
        ['suggested', product.suggested_selling_price],
        ['minimum', product.minimum_selling_price],
        ['maximum', product.maximum_selling_price],
    ] as const;

    return (
        <SectionCard title={t('catalog.browse.selling_guidance')}>
            <dl className="grid grid-cols-3 gap-3 text-sm">
                {rows.map(([key, amount]) => (
                    <div key={key}>
                        <dt className="text-muted-foreground text-xs">
                            {t(`catalog.browse.${key}`)}
                        </dt>
                        <dd>
                            {amount ? (
                                <MoneyAmount amount={amount} />
                            ) : (
                                <span className="text-muted-foreground">
                                    {t('catalog.browse.not_set')}
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </SectionCard>
    );
}
