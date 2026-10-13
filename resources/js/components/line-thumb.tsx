import { cn } from '@/lib/utils';

/**
 * A product's stand-in tile on an order or checkout line.
 *
 * Order lines carry no picture of their own (what was bought is recorded as
 * text), so the tile shows the product's first letter on the surface tone. It is
 * decoration only and hidden from assistive technology; the name sits beside it.
 */
export default function LineThumb({
    name,
    quantity,
    className,
}: {
    name: string;
    quantity?: number;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'bg-muted text-muted-foreground relative flex size-12 shrink-0 items-center justify-center rounded-lg border text-base font-semibold uppercase',
                className,
            )}
        >
            {name.trim().charAt(0) || '·'}
            {quantity !== undefined && (
                <span className="bg-primary text-primary-foreground absolute -top-1.5 -right-1.5 flex min-w-5 items-center justify-center rounded-full px-1 text-[11px] leading-5 font-medium tabular-nums">
                    {quantity}
                </span>
            )}
        </span>
    );
}
