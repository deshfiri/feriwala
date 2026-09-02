import { cn } from '@/lib/utils';
import { signed, type Money, type MoneyDirection } from '@/lib/money';

/**
 * Renders a monetary amount from the server.
 *
 * Always tabular, so a column of figures aligns on the decimal and an outlier
 * can be spotted without reading every row (§33.7). Direction is passed in
 * rather than inferred from the sign, because ledger rows store a debit as a
 * positive amount with a debit type — inferring would colour half the statement
 * wrongly.
 */
export default function MoneyAmount({
    amount,
    direction = 'neutral',
    showSign = false,
    size = 'default',
    className,
}: {
    amount: Money;
    direction?: MoneyDirection;
    showSign?: boolean;
    size?: 'default' | 'large' | 'small';
    className?: string;
}) {
    const sizeClasses = {
        small: 'text-xs',
        default: 'text-sm',
        large: 'text-2xl font-semibold tracking-tight',
    }[size];

    const directionClasses = {
        credit: 'text-credit',
        debit: 'text-debit',
        neutral: '',
    }[direction];

    return (
        <span
            className={cn(
                'tabular-nums',
                sizeClasses,
                directionClasses,
                className,
            )}
            // The exact decimal is available to assistive tech and to anyone
            // inspecting the page, without the formatted value losing its
            // separators.
            title={`${amount.decimal} ${amount.currency}`}
        >
            {showSign ? signed(amount, direction) : amount.formatted}
        </span>
    );
}
