/**
 * The wire shape of a monetary amount, mirroring App\Support\Money\Money.
 *
 * The client never does arithmetic on money. Every figure shown to a user is
 * calculated server-side (requirements.txt §36.1) and arrives already formatted;
 * this module only chooses which representation to render.
 */
export type Money = {
    minor_units: number;
    currency: string;
    /** Exact decimal string, e.g. "1234.56". Never parse this into a float. */
    decimal: string;
    /** Display string with symbol and separators, e.g. "৳1,234.56". */
    formatted: string;
};

export type MoneyDirection = 'credit' | 'debit' | 'neutral';

export function isZero(amount: Money): boolean {
    return amount.minor_units === 0;
}

export function isNegative(amount: Money): boolean {
    return amount.minor_units < 0;
}

/**
 * Whether an amount reads as money coming in or going out.
 *
 * Ledger rows state their own direction — a debit of 500 is stored positive with
 * a debit type, not as -500 — so pass `direction` explicitly wherever the row
 * knows it, and fall back to the sign only when it does not.
 */
export function directionOf(amount: Money): MoneyDirection {
    if (amount.minor_units === 0) return 'neutral';

    return amount.minor_units > 0 ? 'credit' : 'debit';
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
