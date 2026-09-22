import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';

/**
 * The one human-facing money input this application uses (§36.1).
 *
 * Submits the decimal string exactly as typed — "500.25" — never a minor-unit
 * integer. The server is the only place that converts it, through
 * `App\Support\Money\DecimalAmount::parse()`; nothing here does arithmetic on
 * the value, and nothing here needs to, because a currency's decimal
 * separator is not something a browser has to compute.
 *
 * An uncontrolled input, like every other field in this codebase's `Form`
 * components: the name attribute is what the server reads, and a default
 * value is passed once rather than held in React state.
 */
export default function MoneyInput({
    id,
    name,
    label,
    defaultValue,
    required,
    disabled,
    error,
    symbol = '৳',
    helpText,
    className,
    inputClassName,
}: {
    id: string;
    name: string;
    label: string;
    defaultValue?: string;
    required?: boolean;
    disabled?: boolean;
    error?: string;
    /** The currency symbol shown inside the field. Defaults to Taka. */
    symbol?: string;
    helpText?: string;
    className?: string;
    inputClassName?: string;
}) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
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
                    // A hint for the browser's own validation, never the
                    // authority — the server re-validates and re-parses the
                    // same string through the exact decimal boundary.
                    pattern="^\d+(\.\d{1,2})?$"
                    placeholder="0.00"
                    defaultValue={defaultValue}
                    required={required}
                    disabled={disabled}
                    aria-invalid={error ? true : undefined}
                    className={cn('pl-7', inputClassName)}
                />
            </div>
            {helpText && (
                <p className="text-muted-foreground text-xs">{helpText}</p>
            )}
            <InputError message={error} />
        </div>
    );
}
