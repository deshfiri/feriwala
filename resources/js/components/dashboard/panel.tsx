import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A titled block on the dashboard.
 *
 * Deliberately thin. `ui/card` is the vendored shadcn primitive and stays that
 * way; this is the one place the dashboard's own spacing and heading rhythm are
 * decided, so twelve panels cannot each invent their own.
 *
 * The heading is a real `h2`. A dashboard is a page of sections, and someone
 * navigating it by headings should be able to jump between them.
 */
export default function Panel({
    title,
    description,
    action,
    children,
    className,
    contentClassName,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    contentClassName?: string;
}) {
    return (
        <section
            className={cn(
                'bg-card border-border flex flex-col rounded-xl border shadow-sm',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
                <div className="min-w-0 space-y-0.5">
                    <h2 className="leading-none font-semibold">{title}</h2>
                    {description && (
                        <p className="text-muted-foreground text-sm">
                            {description}
                        </p>
                    )}
                </div>

                {action && <div className="shrink-0">{action}</div>}
            </header>

            <div className={cn('flex-1 px-5 pt-4 pb-5', contentClassName)}>
                {children}
            </div>
        </section>
    );
}
