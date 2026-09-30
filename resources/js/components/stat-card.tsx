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
    const hasFooter = Boolean(change || hint);

    /*
     * The icon tile sits to the left of label and figure together, after
     * Isomorphic. It is positioned against the card rather than wrapped in a
     * row with them, because `dt` and `dd` must stay the card's direct
     * children for the description list to hold together.
     */
    const inset = Icon
        ? isCompact
            ? 'pl-[3.75rem]'
            : 'pl-[4.75rem]'
        : isCompact
          ? 'pl-4'
          : 'pl-5';

    return (
        <Card className={cn('relative gap-0 py-0', className)}>
            <dt
                className={cn(
                    'text-muted-foreground min-w-0 text-sm font-medium',
                    inset,
                    isCompact ? 'pt-4 pr-4' : 'pt-5 pr-5',
                )}
            >
                {Icon && (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute flex items-center justify-center rounded-xl',
                            isCompact
                                ? 'top-4 left-4 size-9'
                                : 'top-5 left-5 size-11',
                            toneIconClasses[tone],
                        )}
                    >
                        <Icon className={isCompact ? 'size-4' : 'size-5'} />
                    </span>
                )}
                {label}
            </dt>

            <dd>
                <div
                    className={cn(
                        'tabular font-heading mt-1 leading-tight font-semibold tracking-tight',
                        inset,
                        isCompact ? 'pr-4 pb-4 text-lg' : 'pr-5 pb-5 text-2xl',
                    )}
                >
                    {value}
                </div>

                {hasFooter && (
                    <div
                        className={cn(
                            'flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-dashed text-xs',
                            isCompact ? 'mx-4 py-3' : 'mx-5 py-3.5',
                        )}
                    >
                        {change && ChangeIcon && (
                            <p
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-semibold',
                                    changeClasses[change.direction],
                                )}
                            >
                                <ChangeIcon
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                {change.label}
                            </p>
                        )}

                        {hint && (
                            <p className="text-muted-foreground text-pretty">
                                {hint}
                            </p>
                        )}
                    </div>
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
