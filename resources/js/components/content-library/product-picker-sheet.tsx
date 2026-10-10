import { Check, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductSummary from '@/components/product-links/product-summary';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLinkSummary } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The Products chosen so far, in the order picked. */
    selected: ProductLinkSummary[];
    onChange: (next: ProductLinkSummary[]) => void;
    /** A search endpoint taking `q`, which lists Products even for an empty `q`. */
    searchUrl: string;
};

/**
 * A right-hand panel that opens already listing Products. Searching narrows the
 * list by BPC, title, SKU or barcode, and each row toggles in or out of the
 * selection, so several Products can be chosen without leaving the panel.
 */
export default function ProductPickerSheet({
    open,
    onOpenChange,
    selected,
    onChange,
    searchUrl,
}: Props) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ProductLinkSummary[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setResults(null);
            setFailed(false);

            return;
        }

        const controller = new AbortController();
        // Typing waits a moment; the first, empty load does not.
        const timer = window.setTimeout(
            async () => {
                setFailed(false);
                setResults(null);

                try {
                    const response = await fetch(
                        `${searchUrl}?${new URLSearchParams({ q: query.trim() }).toString()}`,
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
                        throw new Error('listing failed');
                    }

                    const body = (await response.json()) as {
                        data: ProductLinkSummary[];
                    };

                    setResults(body.data);
                } catch (error) {
                    if ((error as Error).name !== 'AbortError') {
                        setFailed(true);
                    }
                }
            },
            query === '' ? 0 : 300,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, searchUrl]);

    const isSelected = (product: ProductLinkSummary) =>
        selected.some((candidate) => candidate.id === product.id);

    const toggle = (product: ProductLinkSummary) =>
        onChange(
            isSelected(product)
                ? selected.filter((candidate) => candidate.id !== product.id)
                : [...selected, product],
        );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>{t('content_library.picker.title')}</SheetTitle>
                    <SheetDescription>
                        {t('content_library.picker.description')}
                    </SheetDescription>
                </SheetHeader>

                <div className="px-4">
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
                </div>

                <div
                    className="min-h-0 flex-1 space-y-2 overflow-y-auto px-4"
                    aria-live="polite"
                >
                    {failed && (
                        <p role="alert" className="text-danger text-sm">
                            {t('product_links.search.failed')}
                        </p>
                    )}

                    {!failed && results === null && (
                        <div className="flex justify-center py-8">
                            <Spinner />
                        </div>
                    )}

                    {results !== null && results.length === 0 && (
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
                                                variant={
                                                    isSelected(product)
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={isSelected(
                                                    product,
                                                )}
                                                onClick={() => toggle(product)}
                                            >
                                                {isSelected(product) ? (
                                                    <Check
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <Plus
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                                {isSelected(product)
                                                    ? t(
                                                          'content_library.picker.selected',
                                                      )
                                                    : t(
                                                          'content_library.picker.select',
                                                      )}
                                            </Button>
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {results !== null && results.length >= 40 && (
                        <p className="text-muted-foreground pb-2 text-xs">
                            {t('content_library.picker.narrow')}
                        </p>
                    )}
                </div>

                <SheetFooter>
                    <p className="text-muted-foreground me-auto self-center text-sm">
                        {t('content_library.picker.count', {
                            count: selected.length,
                        })}
                    </p>
                    <Button type="button" onClick={() => onOpenChange(false)}>
                        {t('content_library.picker.done')}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
