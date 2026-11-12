import { Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import MoneyAmount from '@/components/money-amount';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { Money } from '@/lib/money';
import { cn } from '@/lib/utils';

export type ProductOption = { sku: string; label: string; price: Money };

/**
 * A product picker you can type into.
 *
 * Typing asks the server for matching products (name or SKU) after a short
 * pause, so a storefront with thousands of products never loads them all. The
 * list shows each product's selling price as the server sent it. Arrow keys,
 * Enter and Escape work; a chosen product shows as a chip that clears with one
 * click.
 */
export default function ProductSearchSelect({
    value,
    selectedLabel,
    onSelect,
    onClear,
    searchUrl,
    disabled,
    ariaLabel,
}: {
    /** The chosen SKU, or an empty string. */
    value: string;
    /** What to call the chosen product. */
    selectedLabel: string | null;
    onSelect: (option: ProductOption) => void;
    onClear: () => void;
    /** Builds the request for a search term; the answer is `{ data: ProductOption[] }`. */
    searchUrl: (term: string) => string;
    disabled?: boolean;
    ariaLabel: string;
}) {
    const { t } = useTranslation();
    const listId = useId();
    const box = useRef<HTMLDivElement>(null);
    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<ProductOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(0);

    useEffect(() => {
        if (!open || disabled) {
            return;
        }

        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(searchUrl(term), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const body = (await response.json()) as {
                    data: ProductOption[];
                };

                setResults(body.data);
                setActive(0);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 200);

        return () => window.clearTimeout(timer);
    }, [open, term, disabled, searchUrl]);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (!box.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    const choose = (option: ProductOption) => {
        onSelect(option);
        setTerm('');
        setOpen(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setOpen(true);
            setActive((index) => Math.min(index + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter' && open && results[active]) {
            event.preventDefault();
            choose(results[active]);
        } else if (event.key === 'Escape' && open) {
            event.stopPropagation();
            setOpen(false);
        }
    };

    if (value !== '') {
        return (
            <div className="border-input bg-surface-subtle flex h-9 items-center justify-between gap-2 rounded-md border px-3 text-sm">
                <span className="min-w-0 truncate">
                    {selectedLabel ?? value}{' '}
                    <span className="text-muted-foreground text-xs">
                        ({value})
                    </span>
                </span>
                <button
                    type="button"
                    onClick={onClear}
                    disabled={disabled}
                    className="text-muted-foreground hover:text-foreground shrink-0"
                    aria-label={t('store_orders.create.change_product')}
                >
                    <X className="size-4" aria-hidden="true" />
                </button>
            </div>
        );
    }

    return (
        <div ref={box} className="relative">
            <Search
                className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                aria-hidden="true"
            />
            <Input
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-label={ariaLabel}
                autoComplete="off"
                className="pl-9"
                placeholder={t('store_orders.create.product_search')}
                value={term}
                disabled={disabled}
                onChange={(event) => {
                    setTerm(event.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onKeyDown={onKeyDown}
            />

            {open && !disabled && (
                <ul
                    id={listId}
                    role="listbox"
                    className="bg-popover text-popover-foreground absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-md border shadow-md"
                >
                    {results.length === 0 ? (
                        <li className="text-muted-foreground px-3 py-2 text-sm">
                            {loading
                                ? t('store_orders.create.searching')
                                : t('store_orders.create.no_products')}
                        </li>
                    ) : (
                        results.map((option, index) => (
                            <li
                                key={option.sku}
                                role="option"
                                aria-selected={index === active}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(option);
                                }}
                                onMouseEnter={() => setActive(index)}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm',
                                    index === active && 'bg-accent',
                                )}
                            >
                                <span className="min-w-0">
                                    <span className="block truncate">
                                        {option.label}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {option.sku}
                                    </span>
                                </span>
                                <MoneyAmount amount={option.price} />
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
}
