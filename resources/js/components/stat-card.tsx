import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

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
 * `tone`, when given, renders the icon inside a coloured badge rather than as a
 * bare muted glyph — purely decorative, so a card with no natural tone (or no
 * icon at all) is just as correct without it.
 */
export default function StatCard({
    label,
    value,
    hint,
    change,
    icon: Icon,
    tone,
    className,
}: {
    label: string;
    /** Pre-formatted server-side. Money should come through `MoneyAmount`. */
    value: ReactNode;
    hint?: string;
    change?: { label: string; direction: 'up' | 'down' | 'flat' };
    icon?: LucideIcon;
    tone?: StatCardTone;
    className?: string;
}) {
    return (
        <Card className={cn('gap-0 px-5 py-4', className)}>
            <div className="flex items-start justify-between gap-3">
                <dt className="text-muted-foreground text-xs font-medium">
                    {label}
                </dt>

                {Icon &&
                    (tone ? (
                        <span
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                toneIconClasses[tone],
                            )}
                        >
                            <Icon aria-hidden="true" className="size-4" />
                        </span>
                    ) : (
                        <Icon
                            aria-hidden="true"
                            className="text-muted-foreground size-4 shrink-0"
                        />
                    ))}
            </div>

            <dd className="mt-2 space-y-1">
                <div className="tabular text-2xl leading-none font-semibold tracking-tight">
                    {value}
                </div>

                {change && (
                    <p
                        className={cn(
                            'text-xs font-medium',
                            change.direction === 'up' && 'text-success',
                            change.direction === 'down' && 'text-danger',
                            change.direction === 'flat' &&
                                'text-muted-foreground',
                        )}
                    >
                        {change.label}
                    </p>
                )}

                {hint && (
                    <p className="text-muted-foreground text-xs">{hint}</p>
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
