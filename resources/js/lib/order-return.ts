import type { StatusTone } from '@/lib/status';

/**
 * Where a return stands, in a tone beside its label (§33.9, P6-12).
 *
 * The tone is a second carrier, never the only one: every use sits beside the
 * translated status.
 */
export function returnStatusTone(status: string): StatusTone {
    switch (status) {
        case 'refunded':
            return 'success';
        case 'approved':
        case 'received':
            return 'info';
        case 'rejected':
        case 'cancelled':
            return 'danger';
        case 'requested':
            return 'warning';
        default:
            return 'neutral';
    }
}

/**
 * Where a return's money stands. A refund waiting for a person to settle it
 * by hand is a warning, not a failure: nothing went wrong, somebody has to act.
 */
export function returnRefundTone(state: string): StatusTone {
    switch (state) {
        case 'completed':
            return 'success';
        case 'failed':
            return 'danger';
        case 'pending':
        case 'manual_review':
            return 'warning';
        default:
            return 'neutral';
    }
}
