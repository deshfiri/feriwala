import type { StatusTone } from '@/lib/status';
import type { AppliedRule } from '@/types/referral';

/**
 * How a commission's status reads (D24). Never colour alone: every pill also
 * carries its label.
 */
export function commissionStatusTone(status: string): StatusTone {
    switch (status) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'info';
        case 'reversal_owed':
            return 'danger';
        case 'reversed':
        case 'cancelled':
            return 'warning';
        default:
            return 'neutral';
    }
}

/** The rule a commission applied, in a few characters: "10%" or "৳100.00". */
export function describeRule(rule: AppliedRule): string {
    return rule.type === 'percentage'
        ? `${rule.percent ?? '0'}%`
        : (rule.amount?.formatted ?? '');
}

/** A referred business's plain state, never more (D24). */
export function referredStateTone(state: string): StatusTone {
    return state === 'active'
        ? 'success'
        : state === 'joining'
          ? 'info'
          : 'neutral';
}
