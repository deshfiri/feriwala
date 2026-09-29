import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Shared frame for the non-content states a screen can be in.
 *
 * Every important screen needs empty, error, permission-denied, and offline
 * states as well as its loading skeleton (§33.10). Giving them one frame means
 * they are consistent everywhere and cheap enough to add that nobody skips them.
 */
export default function StateShell({
    icon: Icon,
    tone = 'neutral',
    title,
    description,
    action,
    className,
}: {
    icon: LucideIcon;
    tone?: 'neutral' | 'danger' | 'warning';
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}) {
    const toneClasses = {
        neutral: 'bg-surface-subtle text-muted-foreground border-border',
        danger: 'bg-danger-subtle text-danger border-danger/20',
        warning: 'bg-warning-subtle text-warning border-warning/20',
    }[tone];

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-4 px-6 py-12 text-center',
                className,
            )}
        >
            <div
                className={cn(
                    'flex size-12 items-center justify-center rounded-xl border shadow-xs',
                    toneClasses,
                )}
            >
                <Icon aria-hidden="true" className="size-5" />
            </div>

            <div className="space-y-1.5">
                <p className="text-base font-semibold">{title}</p>
                {description && (
                    <p className="text-muted-foreground mx-auto max-w-md text-sm text-balance">
                        {description}
                    </p>
                )}
            </div>

            {action && <div className="pt-1">{action}</div>}
        </div>
    );
}
