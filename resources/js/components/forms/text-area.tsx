import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A multi-line text control matching `Input`'s look. The KYC and catalogue
 * screens each carried the same long class string; one place keeps them in
 * step with the token set.
 */
export default function TextArea({
    className,
    ...props
}: ComponentProps<'textarea'>) {
    return (
        <textarea
            rows={3}
            {...props}
            className={cn(
                'border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]',
                className,
            )}
        />
    );
}
