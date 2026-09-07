import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The gutters and vertical rhythm every authenticated screen sits in (§33.2).
 *
 * Pages used to hand-roll `space-y-6 p-4`, which meant the gutter was whatever
 * the last person typed — and a screen that disagrees with its neighbour by four
 * pixels is the kind of thing nobody reports and everybody feels. One place sets
 * it now: 16px on a phone, 24px from `sm` up.
 *
 * `width` is about reading distance, not taste. A table wants the whole viewport;
 * a form does not, because a 1600px-wide input is harder to fill in than a narrow
 * one, not easier.
 */
export default function PageContainer({
    children,
    width = 'full',
    className,
}: {
    children: ReactNode;
    /** `full` for tables and dashboards, `narrow` for forms and detail panels. */
    width?: 'full' | 'narrow';
    className?: string;
}) {
    return (
        <div
            className={cn(
                'w-full flex-1 p-4 sm:p-6',
                'space-y-5 sm:space-y-6',
                width === 'narrow' && 'mx-auto max-w-3xl',
                className,
            )}
        >
            {children}
        </div>
    );
}
