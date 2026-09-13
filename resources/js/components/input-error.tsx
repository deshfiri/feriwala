import { AlertCircle } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * A validation message under its own field.
 *
 * Set exactly as `FormField` sets its error — same size, same colour token, same
 * mark — so a form that mixes the two does not show two kinds of error. The mark
 * is what keeps the message distinguishable from help text for a reader who
 * cannot tell the red from the grey (§33.9).
 */
export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p
            {...props}
            className={cn(
                'text-danger flex items-start gap-1.5 text-xs font-medium',
                className,
            )}
        >
            <AlertCircle
                aria-hidden="true"
                className="mt-px size-3.5 shrink-0"
            />
            <span>{message}</span>
        </p>
    ) : null;
}
