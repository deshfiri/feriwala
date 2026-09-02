import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Title, description, and quick actions for an ERP page (§33.2).
 *
 * The description is not decoration — it is where a page says what it is for, so
 * someone who lands on "Settlements" without context can tell whether they are
 * in the right place.
 */
export default function PageHeader({
    title,
    description,
    actions,
    className,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-0.5">
                <h1 className="text-xl font-semibold tracking-tight text-balance">
                    {title}
                </h1>
                {description && (
                    <p className="text-muted-foreground text-sm">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
