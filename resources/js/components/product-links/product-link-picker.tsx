import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import ProductSearchDialog from '@/components/product-links/product-search-dialog';
import ProductSummary from '@/components/product-links/product-summary';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { ProductLinkSummary } from '@/types';

type Props = {
    /** The Products staff have confirmed are the same Product, in the order picked. */
    value: ProductLinkSummary[];
    onChange: (next: ProductLinkSummary[]) => void;
    /** The Product being reviewed, when it already exists: never offered to itself. */
    excludeProductId?: string | null;
    error?: string;
};

/**
 * "Keep this Product unique, or link it with existing Products."
 *
 * Starts empty and stays that way until a person picks: nothing is preselected,
 * and approving without picking anything is the normal, supported outcome.
 * Variations are not matched here; a linked Product's variations stay unmatched
 * (so are never offered as substitutes) until staff match them from the
 * Product's Linked Products section.
 */
export default function ProductLinkPicker({
    value,
    onChange,
    excludeProductId = null,
    error,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    const excluded = [
        ...(excludeProductId ? [excludeProductId] : []),
        ...value.map((product) => product.id),
    ];

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">
                {t('product_links.picker.heading')}
            </legend>
            <p className="text-muted-foreground text-xs">
                {t('product_links.picker.help')}
            </p>

            {value.length === 0 ? (
                <p className="text-sm">{t('product_links.picker.unique')}</p>
            ) : (
                <ul className="divide-border divide-y rounded-md border">
                    {value.map((product) => (
                        <li key={product.id} className="p-3">
                            <ProductSummary
                                product={product}
                                action={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-7"
                                        onClick={() =>
                                            onChange(
                                                value.filter(
                                                    (candidate) =>
                                                        candidate.id !==
                                                        product.id,
                                                ),
                                            )
                                        }
                                        aria-label={t(
                                            'product_links.picker.remove',
                                            { name: product.name },
                                        )}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                }
                            />
                        </li>
                    ))}
                </ul>
            )}

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setOpen(true)}
            >
                <Plus className="size-4" aria-hidden="true" />
                {t('product_links.picker.add')}
            </Button>

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}

            <ProductSearchDialog
                open={open}
                onOpenChange={setOpen}
                excludeIds={excluded}
                pickedIds={value.map((product) => product.id)}
                onPick={(product) => {
                    onChange([...value, product]);
                    setOpen(false);
                }}
            />
        </fieldset>
    );
}
