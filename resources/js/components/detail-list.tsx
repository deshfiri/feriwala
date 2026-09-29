import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Label-and-value pairs on a detail screen (§33.2).
 *
 * A `<dl>`, because that is what these are: "Gateway, bKash" is one fact, and
 * marking it up as two unrelated lines loses that for anyone reading the page
 * structurally. Screens had each grown a private `Detail` helper or a `field()`
 * function to produce this, with the label a different size on every one.
 */
export default function DetailList({
    children,
    columns = 2,
    className,
}: {
    children: ReactNode;
    /** How many pairs share a row once there is room; one on a phone. */
    columns?: 1 | 2 | 3;
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'grid gap-x-6 gap-y-4',
                columns === 2 && 'sm:grid-cols-2',
                columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
                className,
            )}
        >
            {children}
        </dl>
    );
}

/**
 * One pair in a {@see DetailList}.
 *
 * `span` gives a long value — a reason, a list of fields — the whole row, so it
 * wraps as a paragraph instead of as a narrow column.
 */
export function DetailItem({
    label,
    children,
    span = false,
    className,
}: {
    label: string;
    children: ReactNode;
    span?: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'min-w-0 space-y-1',
                span && 'sm:col-span-full',
                className,
            )}
        >
            <dt className="text-muted-foreground text-xs font-medium">
                {label}
            </dt>
            <dd className="text-foreground text-sm font-medium break-words">
                {children}
            </dd>
        </div>
    );
}
