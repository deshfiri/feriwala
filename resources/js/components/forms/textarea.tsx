import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A multi-line text field that matches `Input`.
 *
 * The vendored primitives ship no textarea, so screens had been styling their
 * own — reasons, notes, instructions — each slightly differently. The reason a
 * financial action is taken is exactly the field that should look like it
 * matters, so it gets the same border, focus ring, and invalid state as every
 * other control.
 */
export default function Textarea({
    className,
    ...props
}: ComponentProps<'textarea'>) {
    return (
        <textarea
            data-slot="textarea"
            className={cn(
                'border-input placeholder:text-muted-foreground dark:bg-input/30 flex min-h-16 w-full min-w-0 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                className,
            )}
            {...props}
        />
    );
}
