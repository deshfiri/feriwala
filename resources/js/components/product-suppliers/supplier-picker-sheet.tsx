import { Check, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import StatusPill from '@/components/status-pill';
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
import type { AssignableSupplier } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selected: AssignableSupplier[];
    onChange: (next: AssignableSupplier[]) => void;
    searchUrl: string;
    /** Suppliers that must not be offered, e.g. those already assigned. */
    excludeIds?: string[];
    /** Rendered above the footer, e.g. a reason field. */
    children?: React.ReactNode;
    confirmLabel: string;
    processing?: boolean;
    onConfirm: () => void;
};

/**
 * A right-hand panel listing approved Suppliers with search. Any number can be
 * ticked, then confirmed in one go.
 */
export default function SupplierPickerSheet({
    open,
    onOpenChange,
    selected,
    onChange,
    searchUrl,
    excludeIds = [],
    children,
    confirmLabel,
    processing = false,
    onConfirm,
}: Props) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [results, setResults] = useState<AssignableSupplier[] | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setPage(1);
    }, [query, open]);

    useEffect(() => {
        if (!open) {
            setQuery('');
            setResults(null);
            setHasMore(false);
            setFailed(false);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(
            async () => {
                setFailed(false);
                setLoading(true);

                if (page === 1) {
                    setResults(null);
                }

                try {
                    const response = await fetch(
                        `${searchUrl}?${new URLSearchParams({ q: query.trim(), page: String(page) }).toString()}`,
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
                        data: AssignableSupplier[];
                        has_more: boolean;
                    };

                    setResults((current) =>
                        page === 1
                            ? body.data
                            : [...(current ?? []), ...body.data],
                    );
                    setHasMore(body.has_more);
                } catch (error) {
                    if ((error as Error).name !== 'AbortError') {
                        setFailed(true);
                    }
                } finally {
                    setLoading(false);
                }
            },
            query === '' || page > 1 ? 0 : 300,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, page, searchUrl]);

    const isSelected = (supplier: AssignableSupplier) =>
        selected.some((candidate) => candidate.id === supplier.id);

    const toggle = (supplier: AssignableSupplier) =>
        onChange(
            isSelected(supplier)
                ? selected.filter((candidate) => candidate.id !== supplier.id)
                : [...selected, supplier],
        );

    const shown = (results ?? []).filter(
        (supplier) => !excludeIds.includes(supplier.id),
    );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>
                        {t('product_suppliers.picker.title')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('product_suppliers.picker.description')}
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
                            placeholder={t('product_suppliers.picker.search')}
                            aria-label={t('product_suppliers.picker.search')}
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
                            {t('product_suppliers.picker.failed')}
                        </p>
                    )}

                    {!failed && results === null && (
                        <div className="flex justify-center py-8">
                            <Spinner />
                        </div>
                    )}

                    {results !== null && shown.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            {t('product_suppliers.picker.none')}
                        </p>
                    )}

                    {shown.length > 0 && (
                        <ul className="divide-border divide-y rounded-md border">
                            {shown.map((supplier) => (
                                <li
                                    key={supplier.id}
                                    className="flex items-center justify-between gap-3 p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">
                                            {supplier.name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {supplier.reference}
                                        </p>
                                    </div>
                                    <StatusPill
                                        tone={supplier.status_tone}
                                        label={supplier.status_label}
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isSelected(supplier)
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={isSelected(supplier)}
                                        onClick={() => toggle(supplier)}
                                    >
                                        {isSelected(supplier) ? (
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
                                        {isSelected(supplier)
                                            ? t(
                                                  'product_suppliers.picker.selected',
                                              )
                                            : t(
                                                  'product_suppliers.picker.select',
                                              )}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {hasMore && (
                        <div className="flex justify-center pb-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={loading}
                                onClick={() =>
                                    setPage((current) => current + 1)
                                }
                            >
                                {loading && <Spinner />}
                                {t('product_suppliers.picker.load_more')}
                            </Button>
                        </div>
                    )}
                </div>

                {children && <div className="space-y-2 px-4">{children}</div>}

                <SheetFooter>
                    <p className="text-muted-foreground me-auto self-center text-sm">
                        {t('product_suppliers.picker.count', {
                            count: selected.length,
                        })}
                    </p>
                    <Button
                        type="button"
                        disabled={selected.length === 0 || processing}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
