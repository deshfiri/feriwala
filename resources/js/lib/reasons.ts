/**
 * The ready-made reasons offered wherever a person has to say why they are
 * doing something, grouped by the part of the system they are doing it in.
 * Each key is a line in `lang/*\/reasons.php` (`reasons.items.<key>`); anything
 * not listed is written by hand under "Other".
 */
export type ReasonContext =
    | 'generic'
    | 'product'
    | 'account'
    | 'stock'
    | 'order'
    | 'return'
    | 'wallet'
    | 'supplier'
    | 'kyc'
    | 'access'
    | 'withdrawal'
    | 'referral';

export const REASON_PRESETS: Record<ReasonContext, string[]> = {
    generic: [
        'data_entry_error',
        'correction',
        'duplicate',
        'requested_by_manager',
        'policy_decision',
        'verified_ok',
        'no_longer_needed',
        'test_data',
    ],
    product: [
        'ready_for_sale',
        'content_approved',
        'added_by_mistake',
        'duplicate_product',
        'discontinued_by_supplier',
        'out_of_stock_long_term',
        'quality_issue',
        'seasonal_end',
        'replaced_by_new_product',
        'pricing_review_done',
    ],
    account: [
        'documents_verified',
        'documents_incomplete',
        'suspicious_activity',
        'policy_violation',
        'requested_by_owner',
        'payment_overdue',
        'issue_resolved',
        'data_correction',
    ],
    stock: [
        'stocktake_correction',
        'damaged_goods',
        'expired_goods',
        'lost_goods',
        'returned_stock',
        'supplier_receipt',
        'warehouse_transfer',
        'data_entry_error',
    ],
    order: [
        'customer_request',
        'out_of_stock',
        'payment_issue',
        'address_issue',
        'fraud_suspected',
        'supplier_delay',
        'duplicate_order',
        'courier_issue',
        'best_source_confirmed',
    ],
    return: [
        'wrong_item',
        'damaged_item',
        'not_as_described',
        'customer_changed_mind',
        'return_window_expired',
        'approved_after_inspection',
        'item_not_received',
    ],
    wallet: [
        'manual_correction',
        'refund_issued',
        'promotion_credit',
        'chargeback',
        'duplicate_entry',
        'bank_reconciliation',
    ],
    supplier: [
        'rate_agreed',
        'stock_updated',
        'quality_issue',
        'documents_verified',
        'documents_missing',
        'price_too_high',
        'duplicate_listing',
        'capacity_changed',
    ],
    kyc: [
        'documents_clear',
        'documents_unclear',
        'details_mismatch',
        'document_expired',
        'more_information_needed',
    ],
    access: [
        'role_change',
        'new_responsibility',
        'left_the_team',
        'security_review',
        'requested_by_manager',
    ],
    withdrawal: [
        'verified_and_paid',
        'insufficient_balance',
        'details_mismatch',
        'limit_exceeded',
        'suspected_fraud',
        'bank_failure',
    ],
    referral: ['plan_update', 'policy_change', 'correction', 'fraud_review'],
};
