/**
 * The wire shape of a monetary amount, mirroring App\Support\Money\Money.
 *
 * The client never does arithmetic on money. Every figure shown to a user is
 * calculated server-side (requirements.txt §36.1) and arrives already formatted;
 * this module only chooses which representation to render.
 */
export type Money = {
    currency: string;
    /** Exact decimal string, e.g. "1234.56". Never parse this into a float. */
    amount: string;
    /** Display string with symbol and separators, e.g. "৳1,234.56". */
    formatted: string;
};

export type MoneyDirection = 'credit' | 'debit' | 'neutral';

/**
 * Whether a decimal string is exactly zero, by pattern rather than by parsing
 * it into a float (D26, §36.1). The server never sends a negative zero
 * (Money::normalize() collapses it), so `-0.00` is not a case this needs to
 * handle, but the pattern tolerates a leading sign anyway.
 */
function isZeroDecimal(decimal: string): boolean {
    return /^-?0+(\.0+)?$/.test(decimal);
}

export function isZero(amount: Money): boolean {
    return isZeroDecimal(amount.amount);
}

export function isNegative(amount: Money): boolean {
    return !isZeroDecimal(amount.amount) && amount.amount.startsWith('-');
}

export function isPositive(amount: Money): boolean {
    return !isZeroDecimal(amount.amount) && !amount.amount.startsWith('-');
}

/**
 * Whether an amount reads as money coming in or going out.
 *
 * Ledger rows state their own direction — a debit of 500 is stored positive with
 * a debit type, not as -500 — so pass `direction` explicitly wherever the row
 * knows it, and fall back to the sign only when it does not.
 */
export function directionOf(amount: Money): MoneyDirection {
    if (isZero(amount)) return 'neutral';

    return isNegative(amount) ? 'debit' : 'credit';
}

/**
 * Prefix a figure with its direction for a ledger or statement view.
 *
 * Uses a real minus sign (U+2212) rather than a hyphen, so columns of figures
 * align and the sign is unmistakable at small sizes.
 */
export function signed(amount: Money, direction: MoneyDirection): string {
    const magnitude = amount.formatted.replace(/^[-−]/, '');

    if (direction === 'credit') return `+${magnitude}`;
    if (direction === 'debit') return `−${magnitude}`;

    return magnitude;
}
