import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * A Taka-decimal control for use inside {@link FormField}'s render prop,
 * where `FormField` already owns the label, description, hint and error
 * (§33.5) and `MoneyInput`'s own label would duplicate it.
 *
 * Submits the decimal string exactly as typed — "500.25" — never a
 * minor-unit integer; the server is the only place that converts it,
 * through `App\Support\Money\DecimalAmount::parse()` (§36.1).
 */
export default function MoneyField({
    id,
    name,
    defaultValue,
    'aria-describedby': ariaDescribedBy,
    'aria-invalid': ariaInvalid,
    'aria-required': ariaRequired,
    symbol = '৳',
    className,
}: {
    id: string;
    name: string;
    defaultValue?: string;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
    'aria-required'?: boolean;
    /** The currency symbol shown inside the field. Defaults to Taka. */
    symbol?: string;
    className?: string;
}) {
    return (
        <div className="relative">
            <span
                aria-hidden="true"
                className="text-muted-foreground pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm"
            >
                {symbol}
            </span>
            <Input
                id={id}
                name={name}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                pattern="^\d+(\.\d{1,2})?$"
                placeholder="0.00"
                defaultValue={defaultValue}
                aria-describedby={ariaDescribedBy}
                aria-invalid={ariaInvalid}
                aria-required={ariaRequired}
                className={cn('pl-7 tabular-nums', className)}
            />
        </div>
    );
}
