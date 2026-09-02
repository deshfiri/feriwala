import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Paginator } from '@/types';

/**
 * Build a compact page list with ellipses: 1 … 4 5 6 … 92.
 *
 * A partner with 40,000 orders has 800 pages; rendering them all would be
 * unusable and slow. First and last stay reachable so "jump to the end" works.
 */
function pageWindow(current: number, last: number): (number | 'gap')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const pages = new Set<number>([1, last, current]);

    for (const offset of [-1, 1]) {
        const page = current + offset;

        if (page > 1 && page < last) {
            pages.add(page);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const result: (number | 'gap')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            result.push('gap');
        }

        result.push(page);
    });

    return result;
}

export default function TablePagination<T>({
    paginator,
    onPageChange,
    className,
}: {
    paginator: Paginator<T>;
    onPageChange: (page: number) => void;
    className?: string;
}) {
    const { t } = useTranslation();
    const {
        current_page: current,
        last_page: last,
        from,
        to,
        total,
    } = paginator;

    if (total === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'border-border flex flex-wrap items-center gap-3 border-t px-4 py-2.5',
                className,
            )}
        >
            <p className="text-muted-foreground text-xs tabular-nums">
                {t('common.table.showing', {
                    from: from ?? 0,
                    to: to ?? 0,
                    total,
                })}
            </p>

            {last > 1 && (
                <nav
                    className="ml-auto flex items-center gap-1"
                    aria-label="Pagination"
                >
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        disabled={current === 1}
                        onClick={() => onPageChange(current - 1)}
                        aria-label="Previous page"
                    >
                        <ChevronLeft className="size-3.5" />
                    </Button>

                    {pageWindow(current, last).map((page, index) =>
                        page === 'gap' ? (
                            <span
                                key={`gap-${index}`}
                                className="text-muted-foreground px-1 text-xs"
                                aria-hidden="true"
                            >
                                …
                            </span>
                        ) : (
                            <Button
                                key={page}
                                variant={
                                    page === current ? 'default' : 'outline'
                                }
                                size="icon"
                                className="size-7 text-xs tabular-nums"
                                aria-current={
                                    page === current ? 'page' : undefined
                                }
                                onClick={() => onPageChange(page)}
                            >
                                {page}
                            </Button>
                        ),
                    )}

                    <Button
                        variant="outline"
                        size="icon"
                        className="size-7"
                        disabled={current === last}
                        onClick={() => onPageChange(current + 1)}
                        aria-label="Next page"
                    >
                        <ChevronRight className="size-3.5" />
                    </Button>
                </nav>
            )}
        </div>
    );
}
