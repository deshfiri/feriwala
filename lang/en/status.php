<?php

/*
 * Every status label shown to a reader, keyed exactly the way
 * App\Support\Status\HasTranslatedLabel looks them up: status.<group>.<value>.
 *
 * <value> is each enum's own ->value — never translate the value itself,
 * only what is read here. A status introduced without a line here still
 * displays (the trait falls back to a humanized version of the value), so
 * this file is a completeness aid, not a hard dependency.
 */

return [
    'account' => [
        'registered' => 'Registered',
        'mobile_verification_pending' => 'Mobile verification pending',
        'email_verification_pending' => 'Email verification pending',
        'kyc_pending' => 'KYC pending',
        'kyc_submitted' => 'KYC submitted',
        'kyc_under_review' => 'KYC under review',
        'kyc_resubmission_required' => 'KYC resubmission required',
        'kyc_approved' => 'KYC approved',
        'kyc_rejected' => 'KYC rejected',
        'package_selection_pending' => 'Package selection pending',
        'payment_pending' => 'Payment pending',
        'payment_verification_pending' => 'Payment verification pending',
        'approval_pending' => 'Approval pending',
        'active' => 'Active',
        'package_renewal_due' => 'Package renewal due',
        'package_expired' => 'Package expired',
        'low_wallet_balance' => 'Low wallet balance',
        'wallet_topup_required' => 'Wallet top-up required',
        'temporarily_restricted' => 'Temporarily restricted',
        'temporarily_disabled' => 'Temporarily disabled',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'order' => [
        'draft' => 'Draft',
        'new' => 'New',
        'pending_confirmation' => 'Pending confirmation',
        'customer_verification_pending' => 'Customer verification pending',
        'confirmed' => 'Confirmed',
        'payment_pending' => 'Payment pending',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'stock_reserved' => 'Stock reserved',
        'ready_for_fulfillment' => 'Ready for fulfillment',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'ready_for_pickup' => 'Ready for pickup',
        'courier_assigned' => 'Courier assigned',
        'shipped' => 'Shipped',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'delivery_failed' => 'Delivery failed',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
        'return_requested' => 'Return requested',
        'return_approved' => 'Return approved',
        'returning' => 'Returning',
        'returned' => 'Returned',
        'refund_pending' => 'Refund pending',
        'partially_refunded' => 'Partially refunded',
        'refunded' => 'Refunded',
    ],

    'order_fulfillment' => [
        'pending_review' => 'Pending review',
        'source_allocation_pending' => 'Source allocation pending',
        'supplier_confirmation_pending' => 'Supplier confirmation pending',
        'processing' => 'Processing',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'ready_for_dispatch' => 'Ready for dispatch',
        'partially_fulfilled' => 'Partially fulfilled',
        'fulfilled' => 'Fulfilled',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
    ],

    'order_delivery' => [
        'not_shipped' => 'Not shipped',
        'courier_assigned' => 'Courier assigned',
        'shipped' => 'Shipped',
        'in_transit' => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered',
        'failed_delivery' => 'Failed delivery',
        'return_requested' => 'Return requested',
        'returned' => 'Returned',
        'refunded' => 'Refunded',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
    ],

    'order_courier' => [
        'unassigned' => 'No courier assigned',
        'assigned' => 'Courier assigned',
        'pickup_requested' => 'Pickup requested',
        'picked_up' => 'Picked up',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'failed_delivery' => 'Failed delivery',
        'returned_to_origin' => 'Returned to origin',
        'cancelled' => 'Cancelled',
    ],

    'payment' => [
        'draft' => 'Draft',
        'initiated' => 'Initiated',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'reconciliation_required' => 'Needs reconciliation',
    ],

    'website' => [
        'setup_pending' => 'Setup pending',
        'deposit_pending' => 'Deposit pending',
        'development' => 'In development',
        'api_connection_pending' => 'API connection pending',
        'active' => 'Active',
        'low_wallet_balance' => 'Low wallet balance',
        'grace_period' => 'Grace period',
        'temporarily_disabled' => 'Temporarily disabled',
        'package_expired' => 'Package expired',
        'domain_renewal_pending' => 'Domain renewal pending',
        'hosting_renewal_pending' => 'Hosting renewal pending',
        'suspended' => 'Suspended',
        'maintenance' => 'Maintenance',
        'closed' => 'Closed',
    ],

    'supplier' => [
        'draft' => 'Draft',
        'verification_pending' => 'Verification pending',
        'kyc_pending' => 'KYC pending',
        'under_review' => 'Under review',
        'correction_required' => 'Correction required',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'listing' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'correction_required' => 'Correction required',
        'approved' => 'Approved',
        'partially_approved' => 'Partially approved',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        'archived' => 'Archived',
    ],

    'withdrawal' => [
        'requested' => 'Requested',
        'under_review' => 'Under review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'processing' => 'Processing',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'reversed' => 'Reversed',
    ],

    'return' => [
        'requested' => 'Requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'received' => 'Received',
        'refunded' => 'Refunded',
        'cancelled' => 'Cancelled',
        'waiting_for_parcel' => 'Waiting for parcel',
        'parcel_received' => 'Parcel received',
        'under_inspection' => 'Under inspection',
        'inspection_approved' => 'Inspection approved',
        'inspection_rejected' => 'Inspection rejected',
        'settlement_pending' => 'Refund or adjustment pending',
        'completed' => 'Completed',
    ],

    'payable' => [
        'pending' => 'Pending',
        'eligible' => 'Eligible',
        'settled' => 'Settled',
        'partially_reversed' => 'Partially reversed',
        'reversed' => 'Reversed',
        'cancelled' => 'Cancelled',
        'on_hold' => 'On hold',
    ],

    'supplier_kyc' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'correction_required' => 'Correction required',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'commission' => [
        'skipped' => 'Not paid',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed',
        'reversal_owed' => 'Reversal owed',
    ],

    'kyc' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'resubmission_required' => 'Resubmission required',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'kyc_purpose' => [
        'onboarding' => 'Onboarding',
        'correction' => 'Correction',
        'reverification' => 'Re-verification',
    ],

    /*
     * What a re-verification costs the business while it is outstanding
     * (§7.4). Read by staff choosing them and by the account holder being
     * told what applies, so each one names the restriction rather than the
     * rule behind it.
     */
    'kyc_consequence' => [
        'warning_only' => 'Notify only',
        'block_new_orders' => 'No new orders',
        'block_publishing' => 'No new product publishing',
        'block_withdrawals' => 'No new withdrawals',
        'suspend_after_deadline' => 'Suspend after the deadline',
    ],

    'offer' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
    ],

    'allocation_source' => [
        'warehouse' => 'Central Warehouse',
        'supplier_offer' => 'Supplier',
    ],

    'allocation' => [
        'active' => 'Active',
        'released' => 'Released',
        'superseded' => 'Replaced',
        'cancelled' => 'Cancelled',
    ],

    'user' => [
        'active' => 'Active',
        'locked' => 'Locked',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
    ],

    'product' => [
        'draft' => 'Draft',
        'pending_review' => 'Pending review',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'out_of_stock' => 'Out of stock',
        'discontinued' => 'Discontinued',
        'archived' => 'Archived',
        'dropshipping_enabled' => 'Partner Selling enabled',
        'dropshipping_disabled' => 'Partner Selling disabled',
        'wholesale_enabled' => 'Wholesale enabled',
        'wholesale_disabled' => 'Wholesale disabled',
    ],

    'stock_reservation' => [
        'active' => 'Active',
        'committed' => 'Committed',
        'released' => 'Released',
        'expired' => 'Expired',
    ],

    'wallet_transaction' => [
        'initiated' => 'Initiated',
        'pending' => 'Pending',
        'on_hold' => 'On hold',
        'under_review' => 'Under review',
        'approved' => 'Approved',
        'available' => 'Available',
        'settled' => 'Settled',
        'paid' => 'Paid',
        'rejected' => 'Rejected',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'reversed' => 'Reversed',
    ],

    'user_package' => [
        'pending_payment' => 'Awaiting payment',
        'active' => 'Active',
        'renewal_due' => 'Renewal due',
        'grace_period' => 'Grace period',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
        'superseded' => 'Replaced',
    ],

    'refund' => [
        'requested' => 'Awaiting decision',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'processed' => 'Refunded',
        'failed' => 'Refund failed',
    ],

    'domain_routing' => [
        'unverified' => 'Not verified',
        'verified' => 'Verified',
        'active' => 'On the storefront',
        'suspended' => 'Suspended',
    ],

    'damage' => [
        'reported' => 'Reported',
        'awaiting_receipt' => 'Waiting for the item',
        'received' => 'Received',
        'under_inspection' => 'Under inspection',
        'liability_review' => 'Liability review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'financial_posting_pending' => 'Waiting to be posted',
        'finalized' => 'Finalized',
        'dispatch_requested' => 'Delivery requested',
        'dispatched' => 'Dispatched',
        'received_by_partner' => 'Received by Partner',
    ],

    'damage_source' => [
        'return_inspection' => 'Found on a returned item',
        'delivered_report' => 'Reported on a delivered item',
    ],

    'damage_condition' => [
        'broken' => 'Broken',
        'torn' => 'Torn',
        'stained' => 'Stained',
        'defective' => 'Defective',
        'missing_parts' => 'Missing parts',
        'water_damaged' => 'Water damaged',
        'crushed' => 'Crushed',
        'other' => 'Other',
    ],

    'damage_cause' => [
        'packing' => 'Packing',
        'warehouse_handling' => 'Warehouse handling',
        'courier_transit' => 'Courier transit',
        'partner_handling' => 'Partner handling',
        'customer_misuse' => 'Customer misuse',
        'manufacturing_defect' => 'Manufacturing defect',
        'unknown' => 'Unknown',
    ],

    'damage_valuation' => [
        'suggested_price' => 'Suggested selling price when sold',
        'platform_retained' => 'Platform share when sold',
        'none' => 'No recorded value',
    ],

    'damage_dispatch' => [
        'requested' => 'Requested',
        'dispatched' => 'Dispatched',
        'received_by_partner' => 'Received',
    ],

    'case_evidence_purpose' => [
        'request' => 'With the request',
        'receipt' => 'At receipt',
        'inspection' => 'At inspection',
        'responsibility_review' => 'At responsibility review',
    ],

    'return_case_type' => [
        'return' => 'Return',
        'exchange' => 'Exchange',
    ],

    'return_charge_kind' => [
        'return_charge' => 'Return charge',
        'exchange_delivery' => 'Exchange delivery',
        'damage_loss' => 'Damage loss',
        'damage_delivery' => 'Damaged-item delivery',
    ],

    'return_charge_status' => [
        'recorded' => 'Recorded (Banij)',
        'posted' => 'Posted',
        'outstanding' => 'Outstanding',
        'settled' => 'Settled',
        'reversed' => 'Reversed',
    ],

    'return_disposition' => [
        'restock' => 'Back on sale',
        'damaged' => 'Damaged',
        'quarantine' => 'Held for inspection',
    ],

    'return_initiator' => [
        'partner' => 'Partner',
        'staff' => 'Staff',
        'customer' => 'Customer (storefront)',
    ],

    'return_party' => [
        'partner' => 'Partner',
        'banij' => 'Banij',
    ],

    'return_reason' => [
        'damaged' => 'Arrived damaged',
        'wrong_item' => 'Wrong item sent',
        'not_as_described' => 'Not as described',
        'missing_parts' => 'Parts missing',
        'changed_mind' => 'Changed their mind',
        'other' => 'Something else',
    ],

    'return_refund_state' => [
        'not_required' => 'No refund',
        'pending' => 'Refund pending',
        'completed' => 'Refunded',
        'failed' => 'Refund failed',
        'manual_review' => 'Needs manual settlement',
    ],
];
