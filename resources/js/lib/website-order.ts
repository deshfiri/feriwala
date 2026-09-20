import type { StatusTone } from '@/lib/status';

/**
 * Where a website order's payment stands, in a tone beside its label (§33.9).
 */
export function orderPaymentTone(state: string): StatusTone {
    switch (state) {
        case 'paid':
            return 'success';
        case 'failed':
        case 'cancelled':
            return 'danger';
        case 'reconciliation':
        case 'confirming':
        case 'awaiting':
            return 'warning';
        default:
            return 'neutral';
    }
}
