import { ChevronDown } from 'lucide-react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * A native `<select>` dressed as the rest of the form controls.
 *
 * Native on purpose. It posts with the surrounding `<Form>` without any wiring,
 * opens the operating system's own picker on a phone, and works before the
 * page has hydrated — which is why screens reach for it over the Radix select.
 * What they should not do is restyle it by hand: five copies of the same class
 * string had drifted a pixel taller than `Input` and to a different corner.
 *
 * `className` lands on the wrapper, so a width such as `w-44` still sizes the
 * control. Everything else — `name`, `value`, `onChange`, `aria-*` — goes to
 * the `<select>` itself.
 *
 * `size="sm"` matches the table toolbar, where the search field and buttons are
 * all 32px tall.
 */
export default function NativeSelect({
    className,
    size = 'default',
    ...props
}: Omit<ComponentProps<'select'>, 'size'> & {
    size?: 'sm' | 'default';
}) {
    return (
        <div
            className={cn(
                'relative',
                size === 'sm' ? 'w-full sm:w-auto' : 'w-full',
                className,
            )}
        >
            <select
                data-slot="native-select"
                className={cn(
                    'border-input dark:bg-input/30 w-full min-w-0 appearance-none rounded-md border bg-transparent pr-9 pl-3 text-sm shadow-xs transition-[color,box-shadow] outline-none',
                    'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                    size === 'sm' ? 'h-8' : 'h-9',
                )}
                {...props}
            />

            <ChevronDown
                aria-hidden="true"
                className="text-muted-foreground pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2"
            />
        </div>
    );
}
