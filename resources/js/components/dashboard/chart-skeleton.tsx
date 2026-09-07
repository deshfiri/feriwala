import { Skeleton } from '@/components/ui/skeleton';

/**
 * Holds the space a deferred chart will occupy.
 *
 * Sized to the chart rather than to a generic block, so the page does not jump
 * when the real data lands — a dashboard that reflows under the cursor is how a
 * click ends up on the wrong thing.
 */
export default function ChartSkeleton({ height = 240 }: { height?: number }) {
    return (
        <div
            aria-hidden="true"
            className="flex items-end gap-2"
            style={{ height }}
        >
            {/*
             * Bars of differing heights read as a chart loading. A single grey
             * rectangle reads as a broken image.
             */}
            {[55, 80, 40, 95, 65, 75].map((percent, index) => (
                <Skeleton
                    key={index}
                    className="flex-1 rounded-md"
                    style={{ height: `${percent}%` }}
                />
            ))}
        </div>
    );
}

/**
 * The same idea for a single figure — a headline number and its caption.
 */
export function FigureSkeleton() {
    return (
        <div aria-hidden="true" className="space-y-2">
            <Skeleton className="h-8 w-32" />
            <Skeleton className="h-3 w-24" />
        </div>
    );
}
