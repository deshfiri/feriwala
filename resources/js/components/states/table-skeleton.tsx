import { cn } from '@/lib/utils';

/**
 * Loading placeholder shaped like the table it stands in for.
 *
 * Matching the real row height and column count keeps the layout from jumping
 * when data arrives — a skeleton that shifts the page on load is worse than no
 * skeleton at all. The height is the same `--table-row-height` token the real
 * rows use, so the two cannot drift apart.
 */
export default function TableSkeleton({
    rows = 6,
    columns = 5,
    className,
}: {
    rows?: number;
    columns?: number;
    className?: string;
}) {
    return (
        <div
            className={cn('divide-border divide-y', className)}
            role="status"
            aria-busy="true"
        >
            <span className="sr-only">Loading</span>

            {Array.from({ length: rows }).map((_, row) => (
                <div
                    key={row}
                    className="flex h-(--table-row-height) items-center gap-6 px-4"
                >
                    {Array.from({ length: columns }).map((_, column) => (
                        <div
                            key={column}
                            className="bg-muted h-2.5 flex-1 animate-pulse rounded-full"
                            style={{
                                // Vary the widths so the block reads as text
                                // rather than a bar chart.
                                maxWidth:
                                    column === 0
                                        ? '9rem'
                                        : column === columns - 1
                                          ? '5rem'
                                          : undefined,
                                animationDelay: `${row * 60}ms`,
                            }}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}
