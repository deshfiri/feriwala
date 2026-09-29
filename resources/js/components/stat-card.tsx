import {
    ArrowDownRight,
    ArrowUpRight,
    Minus,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const changeClasses = {
    up: 'bg-success-subtle text-success',
    down: 'bg-danger-subtle text-danger',
    flat: 'bg-muted text-muted-foreground',
};

const changeIcons = {
    up: ArrowUpRight,
    down: ArrowDownRight,
    flat: Minus,
};

/**
 * The icon badge's colour, reusing the same five-tone vocabulary
 * {@see StatusTone} already teaches across the app, plus `brand` for a
 * card with no particular status connotation. Never a raw hex value — the
 * whole point is that a future brand-colour change only has to touch the
 * `--brand`/`--info`/etc. tokens in `app.css`, not every dashboard.
 */
export type StatCardTone =
    | 'brand'
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral';

const toneIconClasses: Record<StatCardTone, string> = {
    brand: 'bg-brand-subtle text-brand',
    success: 'bg-success-subtle text-success',
    warning: 'bg-warning-subtle text-warning',
    danger: 'bg-danger-subtle text-danger',
    info: 'bg-info-subtle text-info',
    neutral: 'bg-neutral-status-subtle text-neutral-status',
};

/**
 * A single figure at the top of a screen (§33.2).
 *
 * The value is a `<dd>` under a `<dt>` label, because a KPI row is a description
 * list and marking it up as one is what lets a screen reader read "Orders today,
 * 42" instead of two unrelated numbers.
 *
 * `change` never carries its meaning in colour alone (§33.9): the direction is
 * spelled out in the text, so a red figure and a green one still differ when the
 * reader cannot tell them apart. Pass an already-formatted string — a percentage
 * computed in the browser is a percentage the server cannot vouch for (§36.1).
 *
 * `tone` colours the icon tile only. It says what kind of figure this is at a
 * glance; it never says whether the figure is good.
 *
 * `size="compact"` is for a secondary row that qualifies a primary one — the
 * claims on a balance beneath the balance itself — so the two rows read as a
 * hierarchy rather than nine equal numbers.
 */
export default function StatCard({
    label,
    value,
    hint,
    change,
    icon: Icon,
    tone = 'neutral',
    size = 'default',
    className,
}: {
    label: string;
    /** Pre-formatted server-side. Money should come through `MoneyAmount`. */
    value: ReactNode;
    hint?: string;
    change?: { label: string; direction: 'up' | 'down' | 'flat' };
    icon?: LucideIcon;
    tone?: StatCardTone;
    size?: 'default' | 'compact';
    className?: string;
}) {
    const isCompact = size === 'compact';
    const ChangeIcon = change ? changeIcons[change.direction] : null;

    return (
        <Card
            className={cn('gap-0 px-5', isCompact ? 'py-4' : 'py-5', className)}
        >
            <dt className="flex items-start justify-between gap-3">
                <span className="text-muted-foreground min-w-0 text-sm font-medium">
                    {label}
                </span>

                {Icon && (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'flex shrink-0 items-center justify-center rounded-lg',
                            isCompact ? 'size-7' : 'size-9',
                            toneIconClasses[tone],
                        )}
                    >
                        <Icon className={isCompact ? 'size-3.5' : 'size-4'} />
                    </span>
                )}
            </dt>

            <dd className={cn('space-y-2', isCompact ? 'mt-2' : 'mt-3')}>
                <div
                    className={cn(
                        'tabular leading-none font-semibold tracking-tight',
                        isCompact ? 'text-lg' : 'text-2xl',
                    )}
                >
                    {value}
                </div>

                {change && ChangeIcon && (
                    <p
                        className={cn(
                            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium',
                            changeClasses[change.direction],
                        )}
                    >
                        <ChangeIcon aria-hidden="true" className="size-3" />
                        {change.label}
                    </p>
                )}

                {hint && (
                    <p className="text-muted-foreground text-xs text-pretty">
                        {hint}
                    </p>
                )}
            </dd>
        </Card>
    );
}

/**
 * The responsive row a set of {@see StatCard}s sits in.
 *
 * A `<dl>`, so the labels and values above stay paired to anything reading the
 * page structurally rather than visually.
 */
export function StatCardGrid({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'grid gap-4 sm:grid-cols-2 lg:grid-cols-4',
                className,
            )}
        >
            {children}
        </dl>
    );
}
