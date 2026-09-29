import {
    AlertTriangle,
    CheckCircle2,
    Info,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type NoticeTone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

const toneSurfaces: Record<NoticeTone, string> = {
    info: 'border-info/25 bg-info-subtle',
    success: 'border-success/25 bg-success-subtle',
    warning: 'border-warning/30 bg-warning-subtle',
    danger: 'border-danger/25 bg-danger-subtle',
    neutral: 'bg-surface-subtle',
};

const toneMarks: Record<NoticeTone, string> = {
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    neutral: 'text-muted-foreground',
};

const toneIcons: Record<NoticeTone, LucideIcon> = {
    info: Info,
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: XCircle,
    neutral: Info,
};

/**
 * Something the reader has to know before they carry on (§33.9, §33.10).
 *
 * A shortfall, a payment waiting on reconciliation, a round that was sent back.
 * Stated in words with a title and a mark beside them, never by tinting a figure
 * — the tint is only the second carrier of the meaning.
 *
 * `inset` is for a notice sitting inside a card. It takes the tighter corner a
 * nested box should have; one on the page canvas matches the cards around it.
 *
 * `role` defaults to `status`, which is announced without interrupting. Pass
 * `alert` only for something that has just gone wrong because of what the
 * reader did.
 */
export default function Notice({
    tone = 'info',
    title,
    children,
    action,
    icon,
    inset = false,
    role = 'status',
    className,
}: {
    tone?: NoticeTone;
    title?: ReactNode;
    children?: ReactNode;
    /** Buttons or links that resolve what the notice describes. */
    action?: ReactNode;
    icon?: LucideIcon;
    inset?: boolean;
    role?: 'status' | 'alert' | 'note';
    className?: string;
}) {
    const Icon = icon ?? toneIcons[tone];

    return (
        <div
            role={role}
            className={cn(
                'flex gap-3 border px-4 py-3.5',
                inset ? 'rounded-lg' : 'rounded-xl',
                toneSurfaces[tone],
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn('mt-0.5 size-4 shrink-0', toneMarks[tone])}
            />

            <div className="min-w-0 flex-1 text-sm">
                {title && (
                    <p className="text-foreground font-medium">{title}</p>
                )}

                {children && (
                    <div
                        className={cn(
                            'text-muted-foreground space-y-1 text-pretty',
                            title && 'mt-1',
                        )}
                    >
                        {children}
                    </div>
                )}

                {action && (
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {action}
                    </div>
                )}
            </div>
        </div>
    );
}
