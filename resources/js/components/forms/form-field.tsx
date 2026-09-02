import { AlertCircle } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldRenderProps = {
    id: string;
    'aria-describedby': string | undefined;
    'aria-invalid': boolean | undefined;
    'aria-required': boolean | undefined;
};

/**
 * Label, description, control, and error for one form field (§33.5).
 *
 * The control is a render prop rather than a plain child so this component can
 * hand it the accessibility wiring — matching `id`, `aria-describedby` pointing
 * at both the description and the error, `aria-invalid`, `aria-required`. Getting
 * that right once here is the difference between a form that works with a screen
 * reader and one that only looks like it does (§33.9).
 *
 * The error sits directly beneath its own field, never collected in a summary at
 * the top: someone correcting a long KYC form should not have to work out which
 * of fourteen inputs a message refers to.
 */
export default function FormField({
    label,
    children,
    description,
    error,
    required = false,
    hint,
    className,
}: {
    label: string;
    children: (props: FieldRenderProps) => ReactNode;
    /** Guidance shown before the user types. */
    description?: string;
    error?: string;
    required?: boolean;
    /** Secondary note shown after the control, e.g. a format reminder. */
    hint?: string;
    className?: string;
}) {
    const id = useId();
    const descriptionId = description ? `${id}-description` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const hintId = hint ? `${id}-hint` : undefined;

    const describedBy =
        [errorId, descriptionId, hintId].filter(Boolean).join(' ') || undefined;

    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={id} className="flex items-center gap-1">
                {label}
                {required && (
                    <>
                        <span className="text-danger" aria-hidden="true">
                            *
                        </span>
                        <span className="sr-only">(required)</span>
                    </>
                )}
            </Label>

            {description && (
                <p id={descriptionId} className="text-muted-foreground text-xs">
                    {description}
                </p>
            )}

            {children({
                id,
                'aria-describedby': describedBy,
                'aria-invalid': error ? true : undefined,
                'aria-required': required || undefined,
            })}

            {hint && !error && (
                <p id={hintId} className="text-muted-foreground text-xs">
                    {hint}
                </p>
            )}

            {error && (
                <p
                    id={errorId}
                    // Announced when validation returns without stealing focus
                    // from wherever the user currently is.
                    role="alert"
                    className="text-danger flex items-center gap-1.5 text-xs font-medium"
                >
                    <AlertCircle
                        className="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    {error}
                </p>
            )}
        </div>
    );
}
