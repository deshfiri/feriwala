import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * Submit control that cannot be fired twice (§33.5).
 *
 * Disabling while a request is in flight is the whole point: a double-clicked
 * submit on a withdrawal or an order is a duplicate financial transaction, and
 * the server-side idempotency guard should be the second line of defence, not
 * the first.
 *
 * The label stays visible next to the spinner so the button keeps its width and
 * the layout does not jump mid-submit.
 */
export default function SubmitButton({
    children,
    processing = false,
    processingLabel,
    className,
    ...props
}: ComponentProps<typeof Button> & {
    children: ReactNode;
    processing?: boolean;
    /** Optional wording while in flight, e.g. "Submitting…". */
    processingLabel?: string;
}) {
    return (
        <Button
            type="submit"
            disabled={processing || props.disabled}
            aria-busy={processing || undefined}
            className={cn('gap-2', className)}
            {...props}
        >
            {processing && <Spinner className="size-3.5" />}
            {processing && processingLabel ? processingLabel : children}
        </Button>
    );
}
