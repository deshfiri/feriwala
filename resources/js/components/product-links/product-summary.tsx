import { ImageOff } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLinkSummary } from '@/types';

/**
 * One Product as staff recognise it when deciding whether it is the same as
 * another: image, title, BPC, SKU or barcode where there is one, a variation
 * summary and how many Supplier and Warehouse sources it already has.
 *
 * Staff-only — the source counts hint at who supplies a Product.
 */
export default function ProductSummary({
    product,
    action,
}: {
    product: ProductLinkSummary;
    /** Whatever belongs at the end of the row: a button, a badge. */
    action?: React.ReactNode;
}) {
    const { t } = useTranslation();

    const identifiers = [
        product.sku !== product.bpc ? product.sku : null,
        product.barcode,
    ].filter((value): value is string => value !== null && value !== '');

    return (
        <div className="flex items-start gap-3">
            <div className="bg-muted flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                {product.image_url ? (
                    <img
                        src={product.image_url}
                        alt=""
                        className="size-full object-cover"
                    />
                ) : (
                    <ImageOff
                        className="text-muted-foreground size-5"
                        aria-hidden="true"
                    />
                )}
            </div>

            <div className="min-w-0 flex-1 space-y-0.5 text-sm">
                <p className="truncate font-medium">{product.name}</p>
                <p className="text-muted-foreground text-xs">
                    {t('product_links.summary.bpc')}{' '}
                    <span className="font-mono">{product.bpc}</span>
                    {identifiers.length > 0 && (
                        <>
                            {' · '}
                            <span className="font-mono">
                                {identifiers.join(' · ')}
                            </span>
                        </>
                    )}
                </p>
                <p className="text-muted-foreground text-xs">
                    {product.variant_count === 0
                        ? t('product_links.summary.no_variants')
                        : t('product_links.summary.variants', {
                              count: product.variant_count,
                              labels: product.variant_labels.join(', '),
                          })}
                </p>
                <p className="text-muted-foreground text-xs">
                    {t('product_links.summary.sources', {
                        suppliers: product.supplier_sources,
                        warehouses: product.warehouse_sources,
                    })}
                </p>
            </div>

            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
