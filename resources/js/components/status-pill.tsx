import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { statusIcons, statusToneClasses, type StatusTone } from '@/lib/status';

/**
 * A status indicator carrying colour, icon, and label together.
 *
 * The label is required on purpose. A bare coloured dot forces the reader to
 * remember what each colour means, and fails outright for a colour-blind user
 * (§33.9). If space is genuinely too tight for a label, that is a signal the
 * column is wrong, not that the label should go.
 */
export default function StatusPill({
    tone,
    label,
    icon,
    className,
}: {
    tone: StatusTone;
    label: string;
    icon?: LucideIcon;
    className?: string;
}) {
    const Icon = icon ?? statusIcons[tone];

    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-md py-0.5 pr-2 pl-1.5',
                'text-xs font-medium whitespace-nowrap',
                statusToneClasses[tone],
                className,
            )}
        >
            <Icon aria-hidden="true" className="size-3 shrink-0" />
            {label}
        </span>
    );
}
