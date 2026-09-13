import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Title, description, and quick actions for an ERP page (§33.2).
 *
 * The description is not decoration — it is where a page says what it is for, so
 * someone who lands on "Settlements" without context can tell whether they are
 * in the right place.
 *
 * `meta` sits beside the title and is for what the page *is* — a status, say —
 * while `actions` is for what can be *done*. Keeping the two apart is what stops
 * a status pill from reading as a button in the action row.
 *
 * `back` is the way out to the list a detail screen was opened from. It sits
 * above the title rather than among the actions, where it would compete with
 * the one action the page actually exists for.
 */
export default function PageHeader({
    title,
    description,
    meta,
    actions,
    back,
    className,
}: {
    title: string;
    description?: string;
    meta?: ReactNode;
    actions?: ReactNode;
    back?: { href: ComponentProps<typeof Link>['href']; label: string };
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between',
                className,
            )}
        >
            <div className="min-w-0 space-y-1.5">
                {back && (
                    <Link
                        href={back.href}
                        className="text-muted-foreground hover:text-foreground -ml-0.5 inline-flex items-center gap-1.5 rounded-md text-sm font-medium transition-colors"
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        {back.label}
                    </Link>
                )}

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <h1 className="text-xl font-semibold tracking-tight text-balance sm:text-2xl">
                        {title}
                    </h1>
                    {meta}
                </div>

                {description && (
                    <p className="text-muted-foreground max-w-3xl text-sm text-pretty">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                    {actions}
                </div>
            )}
        </div>
    );
}
