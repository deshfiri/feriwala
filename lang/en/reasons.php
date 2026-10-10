<?php

/*
 * Ready-made reasons, offered wherever a person has to say why. Every one is a
 * full sentence (the system asks for at least ten characters), and anything
 * else is written under "Other". Listed by key in resources/js/lib/reasons.ts.
 */
return [
    'choose' => 'Choose a reason…',
    'other' => 'Other (write your own)',
    'write' => 'Write the reason',

    'items' => [
        // General
        'data_entry_error' => 'Entered by mistake; corrected.',
        'correction' => 'Correcting an earlier record.',
        'duplicate' => 'Duplicate of an existing record.',
        'requested_by_manager' => 'Requested by a manager.',
        'policy_decision' => 'Decided under company policy.',
        'verified_ok' => 'Checked and found correct.',
        'no_longer_needed' => 'No longer needed.',
        'test_data' => 'Test data, not a real record.',

        // Product
        'ready_for_sale' => 'Reviewed and ready for sale.',
        'content_approved' => 'Content and prices checked and approved.',
        'added_by_mistake' => 'Added by mistake.',
        'duplicate_product' => 'Duplicate of another product.',
        'discontinued_by_supplier' => 'Discontinued by the supplier.',
        'out_of_stock_long_term' => 'Out of stock for the long term.',
        'quality_issue' => 'Quality problem reported.',
        'seasonal_end' => 'Seasonal product, season has ended.',
        'replaced_by_new_product' => 'Replaced by a newer product.',
        'pricing_review_done' => 'Pricing review completed.',

        // Account
        'documents_verified' => 'Documents checked and verified.',
        'documents_incomplete' => 'Documents are incomplete.',
        'suspicious_activity' => 'Suspicious activity on the account.',
        'policy_violation' => 'Breach of platform policy.',
        'requested_by_owner' => 'Requested by the account owner.',
        'payment_overdue' => 'Payment is overdue.',
        'issue_resolved' => 'The earlier issue has been resolved.',
        'data_correction' => 'Correcting account details.',

        // Stock
        'stocktake_correction' => 'Corrected after a stock count.',
        'damaged_goods' => 'Goods found damaged.',
        'expired_goods' => 'Goods have expired.',
        'lost_goods' => 'Goods lost or missing.',
        'returned_stock' => 'Stock returned to the warehouse.',
        'supplier_receipt' => 'Received from a supplier.',
        'warehouse_transfer' => 'Transferred between warehouses.',

        // Order
        'customer_request' => 'Requested by the customer.',
        'out_of_stock' => 'Item is out of stock.',
        'payment_issue' => 'Payment problem on the order.',
        'address_issue' => 'Delivery address problem.',
        'fraud_suspected' => 'Suspected fraudulent order.',
        'supplier_delay' => 'Supplier cannot deliver in time.',
        'duplicate_order' => 'Duplicate of another order.',
        'courier_issue' => 'Courier problem.',
        'best_source_confirmed' => 'Source chosen after comparing the options.',

        // Return
        'wrong_item' => 'Wrong item was sent.',
        'damaged_item' => 'Item arrived damaged.',
        'not_as_described' => 'Item is not as described.',
        'customer_changed_mind' => 'Customer changed their mind.',
        'return_window_expired' => 'The return window has passed.',
        'approved_after_inspection' => 'Approved after inspecting the item.',
        'item_not_received' => 'Item was not received back.',

        // Wallet
        'manual_correction' => 'Manual correction approved.',
        'refund_issued' => 'Refund issued to the customer.',
        'promotion_credit' => 'Promotional credit.',
        'chargeback' => 'Chargeback from the payment provider.',
        'duplicate_entry' => 'Duplicate entry reversed.',
        'bank_reconciliation' => 'Adjusted after bank reconciliation.',

        // Supplier
        'rate_agreed' => 'Rate agreed with the supplier.',
        'stock_updated' => 'Stock figures updated.',
        'documents_missing' => 'Required documents are missing.',
        'price_too_high' => 'Price is too high.',
        'duplicate_listing' => 'Duplicate of an existing listing.',
        'capacity_changed' => 'Supplier capacity has changed.',

        // KYC
        'documents_clear' => 'Documents are clear and valid.',
        'documents_unclear' => 'Documents are unclear or unreadable.',
        'details_mismatch' => 'Details do not match.',
        'document_expired' => 'A document has expired.',
        'more_information_needed' => 'More information is needed.',

        // Access
        'role_change' => 'Role changed to match duties.',
        'new_responsibility' => 'New responsibility assigned.',
        'left_the_team' => 'Person has left the team.',
        'security_review' => 'Result of a security review.',

        // Withdrawal
        'verified_and_paid' => 'Verified and paid out.',
        'insufficient_balance' => 'Insufficient withdrawable balance.',
        'limit_exceeded' => 'Withdrawal limit exceeded.',
        'suspected_fraud' => 'Suspected fraud.',
        'bank_failure' => 'Bank or mobile wallet rejected the payout.',

        // Referral
        'plan_update' => 'Plan updated.',
        'policy_change' => 'Policy changed.',
        'fraud_review' => 'Under fraud review.',
    ],
];
