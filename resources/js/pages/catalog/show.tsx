import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ImageOff, Paperclip, ShoppingBasket } from 'lucide-react';
import { useState } from 'react';
import WholesaleCartController from '@/actions/App/Http/Controllers/Erp/WholesaleCartController';
import WebsiteProductController from '@/actions/App/Http/Controllers/Erp/WebsiteProductController';
import InputError from '@/components/input-error';
import MoneyAmount from '@/components/money-amount';
import MoneyInput from '@/components/money-input';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { index as dropshippingIndex } from '@/routes/catalog/dropshipping';
import { index as wholesaleIndex } from '@/routes/catalog/wholesale';
import { show as cartShow } from '@/routes/wholesale/cart';
import { index as websiteProducts } from '@/routes/websites/products';
import type {
    BrowseCard,
    BrowseDetail,
    ContentRow,
    SalesChannelName,
} from '@/types';
import { ProductCard } from './browse';

type Props = {
    channel: SalesChannelName;
    account_type: 'conditional' | 'non_conditional';
    product: BrowseDetail;
    /** Only the recommendations this account may itself open on this channel. */
    related: BrowseCard[];
    /**
     * The account's open storefronts, and whether each already sells this
     * (§15, P5-1). Empty on the wholesale screen and for an account with no
     * website.
     */
    websites?: { id: string; name: string; selected: boolean }[];
    /** The product's public identifier, for choosing it for a storefront. */
    product_id?: string;
    /** Updates published for this product, newest first (new feature). */
    content: ContentRow[];
    /** Whether stock can stop an order; when off, out-of-stock products can still be added. */
    stock_enforced?: boolean;
};

/**
 * One product, as a business account sees it on one channel (§13).
 *
 * The server only renders this when the account is eligible for the product on
 * this channel; anybody else gets a 404. Every figure is the server's own —
 * quantity pricing is resolved at each band's starting quantity before it
 * arrives, so a band the base price has since undercut shows what is charged.
 */
export default function CatalogueProduct({
    channel,
    account_type: accountType,
    product,
    related,
    websites = [],
    product_id: productId,
    content,
    stock_enforced: stockEnforced = true,
}: Props) {
    const { t } = useTranslation();
    const [active, setActive] = useState(0);
    const [broken, setBroken] = useState<Set<number>>(() => new Set());

    const current =
        product.media[active] && !broken.has(active)
            ? product.media[active]
            : undefined;

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

                {channel === 'dropshipping' &&
                    productId &&
                    websites.length > 0 && (
                        <SectionCard
                            title={t('catalog.websites.title')}
                            description={t('catalog.websites.description')}
                        >
                            <ul className="divide-border divide-y">
                                {websites.map((website) => (
                                    <li
                                        key={website.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-medium">
                                                {website.name}
                                            </div>
                                            {website.selected && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t(
                                                        'catalog.websites.already',
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {website.selected ? (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                asChild
                                            >
                                                <Link
                                                    href={websiteProducts(
                                                        website.id,
                                                    )}
                                                >
                                                    {t(
                                                        'catalog.websites.manage',
                                                    )}
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Form
                                                {...WebsiteProductController.store.form(
                                                    website.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                transform={(data) => ({
                                                    ...data,
                                                    product: productId,
                                                })}
                                            >
                                                {({ processing, errors }) => (
                                                    <div className="space-y-1">
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            {processing && (
                                                                <Spinner />
                                                            )}
                                                            {t(
                                                                'catalog.websites.add',
                                                            )}
                                                        </Button>
                                                        <InputError
                                                            message={
                                                                errors.product
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>
                    )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="space-y-3">
                        <div className="bg-muted flex aspect-square items-center justify-center overflow-hidden rounded-xl border">
                            {current ? (
                                current.type === 'image' ? (
                                    <img
                                        src={current.url}
                                        alt={current.alt ?? product.name}
                                        className="size-full object-contain"
                                        onError={() =>
                                            setBroken((prev) =>
                                                new Set(prev).add(active),
                                            )
                                        }
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
                                            {item.type === 'image' &&
                                            !broken.has(index) ? (
                                                <img
                                                    src={item.url}
                                                    alt=""
                                                    className="size-full object-cover"
                                                    onError={() =>
                                                        setBroken((prev) =>
                                                            new Set(prev).add(
                                                                index,
                                                            ),
                                                        )
                                                    }
                                                />
                                            ) : item.type === 'image' ? (
                                                <ImageOff
                                                    className="text-muted-foreground m-auto size-4"
                                                    aria-hidden="true"
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
                            <>
                                <WholesaleTerms product={product} />
                                <AddToCart
                                    product={product}
                                    nonConditional={
                                        accountType === 'non_conditional'
                                    }
                                    stockEnforced={stockEnforced}
                                />
                            </>
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

                {content.length > 0 && (
                    <SectionCard
                        title={t('catalog.content.title')}
                        description={t('catalog.content.description')}
                    >
                        <ol className="space-y-0">
                            {content.map((item, index) => (
                                <li
                                    key={item.id}
                                    className="relative flex gap-3"
                                >
                                    <div className="flex flex-col items-center">
                                        <span
                                            className="bg-primary mt-1.5 size-2.5 shrink-0 rounded-full"
                                            aria-hidden="true"
                                        />
                                        {index < content.length - 1 && (
                                            <span
                                                className="bg-border w-px flex-1"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </div>

                                    <div className="min-w-0 flex-1 space-y-2 pb-6">
                                        <div>
                                            <p className="font-medium">
                                                {item.title}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {new Date(
                                                    item.published_at,
                                                ).toLocaleString()}
                                            </p>
                                        </div>

                                        {item.body && (
                                            <p className="text-sm whitespace-pre-line">
                                                {item.body}
                                            </p>
                                        )}

                                        {item.attachment &&
                                            (item.attachment.is_image &&
                                            item.attachment.url ? (
                                                <img
                                                    src={item.attachment.url}
                                                    alt=""
                                                    className="max-h-48 rounded-md border object-cover"
                                                    onError={(event) => {
                                                        event.currentTarget.style.display =
                                                            'none';
                                                    }}
                                                />
                                            ) : (
                                                item.attachment.url && (
                                                    <a
                                                        href={
                                                            item.attachment.url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-primary bg-muted/50 inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm underline"
                                                    >
                                                        <Paperclip
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'catalog.content.post_file',
                                                        )}
                                                    </a>
                                                )
                                            ))}
                                    </div>
                                </li>
                            ))}
                        </ol>
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

/**
 * Put this product in the wholesale cart (§14, P4-4).
 *
 * Sends only which product, which variation and how many. The server checks the
 * quantity rules and stock again and prices the line itself; the numbers shown
 * here are guidance, not what is charged.
 */
function AddToCart({
    product,
    nonConditional,
    stockEnforced,
}: {
    product: BrowseDetail;
    nonConditional: boolean;
    stockEnforced: boolean;
}) {
    const { t } = useTranslation();
    const min = product.min_order_quantity ?? 1;
    const hasVariants = product.variants.length > 0;

    return (
        <SectionCard title={t('wholesale.cart.add')}>
            <Form
                {...WholesaleCartController.store.form()}
                options={{ preserveScroll: true }}
                className="space-y-3"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="product"
                            value={product.slug}
                        />

                        {hasVariants && (
                            <div className="grid gap-2">
                                <Label htmlFor="cart-variant">
                                    {t('wholesale.cart.variation')}
                                </Label>
                                <select
                                    id="cart-variant"
                                    name="variant"
                                    required
                                    defaultValue=""
                                    className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <option value="" disabled>
                                        {t('wholesale.cart.choose_variation')}
                                    </option>
                                    {product.variants.map((variant) => (
                                        <option
                                            key={variant.sku}
                                            value={variant.sku}
                                        >
                                            {variant.label || variant.sku}
                                            {variant.in_stock
                                                ? ''
                                                : ` — ${t('catalog.browse.out_of_stock')}`}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.variant} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="cart-quantity">
                                {t('wholesale.cart.add_quantity')}
                            </Label>
                            <Input
                                id="cart-quantity"
                                name="quantity"
                                type="number"
                                inputMode="numeric"
                                min={min}
                                max={product.max_order_quantity ?? undefined}
                                step={1}
                                defaultValue={min}
                                required
                                className="sm:w-40"
                            />
                            <InputError
                                message={errors.quantity ?? errors.product}
                            />
                        </div>

                        {nonConditional && (
                            <div className="grid gap-2">
                                <MoneyInput
                                    id="cart-resale-amount"
                                    name="resale_amount"
                                    label={t('wholesale.cart.resale_amount')}
                                    defaultValue={
                                        product.suggested_selling_price?.amount
                                    }
                                    required
                                    error={errors.resale_amount}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('wholesale.cart.resale_guidance', {
                                        minimum:
                                            (
                                                product.minimum_selling_price ??
                                                product.wholesale_price
                                            )?.formatted ?? '—',
                                        maximum:
                                            product.maximum_selling_price
                                                ?.formatted ??
                                            t('wholesale.cart.no_maximum'),
                                    })}
                                </p>
                            </div>
                        )}

                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    (stockEnforced && !product.in_stock)
                                }
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <ShoppingBasket
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                                {t('wholesale.cart.add')}
                            </Button>
                            <Link
                                href={cartShow()}
                                className="text-sm underline-offset-4 hover:underline"
                            >
                                {t('wholesale.cart.view_cart')}
                            </Link>
                        </div>
                    </>
                )}
            </Form>
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
