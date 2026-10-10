import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductLinkController from '@/actions/App/Http/Controllers/Admin/ProductLinkController';
import ProductSummary from '@/components/product-links/product-summary';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLinkSummary } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Public ids that must not be offered: the Product itself, ones already linked. */
    excludeIds: string[];
    /** Public ids already picked in this dialog's caller; shown as picked. */
    pickedIds?: string[];
    onPick: (product: ProductLinkSummary) => void;
};

/**
 * Search the catalogue by BPC, title, SKU or barcode and pick a Product.
 *
 * A suggestion list only: nothing is preselected, ranked as "probably the
 * same" or linked here. The caller decides what picking means (a confirmation
 * dialog, or adding to a pending decision).
 */
export default function ProductSearchDialog({
    open,
    onOpenChange,
    excludeIds,
    pickedIds = [],
    onPick,
}: Props) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ProductLinkSummary[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setResults(null);
            setFailed(false);
        }
    }, [open]);

    useEffect(() => {
        if (!open || query.trim().length < 2) {
            setResults(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setSearching(true);
            setFailed(false);

            try {
                const response = await fetch(
                    ProductLinkController.search.url({
                        query: { q: query.trim(), exclude: excludeIds },
                    }),
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );

                if (!response.ok) {
                    throw new Error('search failed');
                }

                const body = (await response.json()) as {
                    data: ProductLinkSummary[];
                };

                setResults(body.data);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setFailed(true);
                }
            } finally {
                setSearching(false);
            }
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, open, excludeIds.join(',')]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('product_links.search.title')}</DialogTitle>
                    <DialogDescription>
                        {t('product_links.search.description')}
                    </DialogDescription>
                </DialogHeader>

                <div className="relative">
                    <Search
                        className="text-muted-foreground absolute top-2.5 left-3 size-4"
                        aria-hidden="true"
                    />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('product_links.search.placeholder')}
                        aria-label={t('product_links.search.placeholder')}
                        className="pl-9"
                        autoFocus
                    />
                </div>

                <div className="max-h-96 overflow-y-auto" aria-live="polite">
                    {failed && (
                        <p role="alert" className="text-danger text-sm">
                            {t('product_links.search.failed')}
                        </p>
                    )}

                    {!failed && results === null && (
                        <p className="text-muted-foreground text-sm">
                            {searching
                                ? t('product_links.search.searching')
                                : t('product_links.search.hint')}
                        </p>
                    )}

                    {!failed && results !== null && results.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            {t('product_links.search.none')}
                        </p>
                    )}

                    {results !== null && results.length > 0 && (
                        <ul className="divide-border divide-y rounded-md border">
                            {results.map((product) => (
                                <li key={product.id} className="p-3">
                                    <ProductSummary
                                        product={product}
                                        action={
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={pickedIds.includes(
                                                    product.id,
                                                )}
                                                onClick={() => onPick(product)}
                                            >
                                                {pickedIds.includes(product.id)
                                                    ? t(
                                                          'product_links.search.picked',
                                                      )
                                                    : t(
                                                          'product_links.search.select',
                                                      )}
                                            </Button>
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
