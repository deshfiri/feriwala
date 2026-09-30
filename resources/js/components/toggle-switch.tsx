import { cn } from '@/lib/utils';

/**
 * An on/off switch.
 *
 * A real `role="switch"` button, so it is announced as "on" or "off" and
 * works from the keyboard. The state is carried by the thumb's position as
 * well as the accent fill, so it still reads in greyscale (§33.9). `label` is
 * required because a bare switch in a row says nothing about what it turns
 * on.
 */
export default function ToggleSwitch({
    checked,
    onCheckedChange,
    label,
    disabled = false,
    busy = false,
    className,
}: {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    /** What the switch turns on, for assistive technology. */
    label: string;
    disabled?: boolean;
    /** A change is being saved: blocks another click until it lands. */
    busy?: boolean;
    className?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            aria-busy={busy || undefined}
            disabled={disabled || busy}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors',
                'focus-visible:ring-ring focus-visible:ring-offset-background focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                'disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary' : 'bg-input',
                busy && 'animate-pulse',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'bg-card pointer-events-none block size-5 rounded-full shadow-sm ring-0 transition-transform',
                    checked ? 'translate-x-5' : 'translate-x-0',
                )}
            />
        </button>
    );
}
