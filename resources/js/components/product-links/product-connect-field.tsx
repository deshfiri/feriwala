import { Search } from 'lucide-react';
import { useState } from 'react';
import ProductLinkController from '@/actions/App/Http/Controllers/Admin/ProductLinkController';
import ProductPickerSheet from '@/components/content-library/product-picker-sheet';
import ProductSummary from '@/components/product-links/product-summary';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLinkSummary } from '@/types';

type Props = {
    /** The Product chosen so far, or null while none is. */
    value: ProductLinkSummary | null;
    onChange: (next: ProductLinkSummary | null) => void;
    /**
     * A Product the listing is already connected to. It is shown as settled —
     * connected once, kept — rather than asking again.
     */
    locked?: ProductLinkSummary | null;
    error?: string;
};

/**
 * Connect a Supplier listing to an existing Product: a button opens the
 * right-hand panel listing every Product with search, and picking one shows it
 * here with a way to change it. Nothing here is typed by hand.
 */
export default function ProductConnectField({
    value,
    onChange,
    locked = null,
    error,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    if (locked !== null) {
        return (
            <div className="space-y-2 rounded-md border p-3">
                <p className="text-sm font-medium">
                    {t('product_links.connect.connected')}
                </p>
                <ProductSummary product={locked} />
                <p className="text-muted-foreground text-xs">
                    {t('product_links.connect.locked_help')}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {value === null ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setOpen(true)}
                >
                    <Search className="size-4" aria-hidden="true" />
                    {t('product_links.connect.choose')}
                </Button>
            ) : (
                <div className="rounded-md border p-3">
                    <ProductSummary
                        product={value}
                        action={
                            <div className="flex gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setOpen(true)}
                                >
                                    {t('product_links.connect.change')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onChange(null)}
                                >
                                    {t('product_links.connect.clear')}
                                </Button>
                            </div>
                        }
                    />
                </div>
            )}

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}

            <ProductPickerSheet
                open={open}
                onOpenChange={setOpen}
                single
                selected={value === null ? [] : [value]}
                onChange={(next) => onChange(next[0] ?? null)}
                searchUrl={ProductLinkController.browse.url()}
                title={t('product_links.connect.panel_title')}
                description={t('product_links.connect.panel_description')}
            />
        </div>
    );
}
