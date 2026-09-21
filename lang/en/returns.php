<?php

/*
 * Returns and their refunds (§18.2, §26.3, contract §6.3, P6-12).
 *
 * The notes are what a customer and their shop read on a return's timeline, so
 * they say what happened in plain words and never how Feriwala keeps its stock.
 */
return [
    'notes' => [
        'requested' => 'Return requested. We will let you know once it has been reviewed.',
        'approved' => 'Return approved. Please send the items back.',
        'rejected' => 'Return not accepted.',
        'received' => 'The returned items arrived and were checked.',
        'refunded' => 'The refund for this return has been settled.',
        'cancelled' => 'The return was withdrawn.',
    ],

    // Why a refund is settled by a person rather than sent to a gateway.
    'refund_notes' => [
        'cash_on_delivery' => 'Paid on delivery: the money never came through a gateway, so a person settles this refund and records how.',
        'never_settled' => 'The payment for this order never settled, so there is nothing a gateway can give back. A person settles this.',
    ],

    'statuses' => [
        'requested' => 'Requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'received' => 'Received',
        'refunded' => 'Refunded',
        'cancelled' => 'Withdrawn',
    ],

    'reasons' => [
        'damaged' => 'Arrived damaged',
        'wrong_item' => 'Wrong item sent',
        'not_as_described' => 'Not as described',
        'missing_parts' => 'Parts missing',
        'changed_mind' => 'Changed their mind',
        'other' => 'Something else',
    ],

    'refund_states' => [
        'not_required' => 'No refund yet',
        'pending' => 'Refund pending',
        'completed' => 'Refunded',
        'failed' => 'Refund failed',
        'manual_review' => 'Refund settled by hand',
    ],

    'dispositions' => [
        'restock' => 'Back on sale',
        'damaged' => 'Damaged',
        'quarantine' => 'Held for inspection',
    ],

    'sources' => [
        'storefront' => 'Storefront',
        'account' => 'Shop owner',
        'staff' => 'Staff',
        'system' => 'System',
        'scheduler' => 'Scheduled check',
        'payment_gateway' => 'Payment gateway',
    ],

    /*
     * The returns desk, for staff. Every action here moves a return along one
     * step and asks for a reason where the step is a decision.
     */
    'admin' => [
        'title' => 'Returns',
        'description' => 'Every return asked for. Those waiting for a decision come first, then goods on their way, then money.',
        'caption' => 'Returns',
        'search' => 'Search by return, order or business',
        'filter_status' => 'Status',
        'all_statuses' => 'Any status',
        'empty' => 'No returns yet',
        'empty_help' => 'Returns appear here once customers ask for them.',
        'no_matches' => 'No returns match',
        'no_matches_help' => 'Try a different reference, business or status.',
        'needs_attention' => 'Refund needs attention',
        'back' => 'All returns',
        'open_order' => 'Open the order',
        'columns' => [
            'reference' => 'Return',
            'account' => 'Business',
            'status' => 'Status',
            'quantity' => 'Units',
            'requested' => 'Asked for',
        ],
        'sections' => [
            'lines' => 'What is coming back',
            'overview' => 'Return',
            'decide' => 'Decide',
            'receive' => 'Receive the goods',
            'refund' => 'Refund',
            'history' => 'History',
        ],
        'fields' => [
            'order' => 'Order',
            'account' => 'Business',
            'website' => 'Website',
            'source' => 'Asked by',
            'reason' => 'Reason',
            'customer_note' => 'Customer note',
            'evidence' => 'Evidence',
            'requested_at' => 'Asked for',
            'decided_at' => 'Decided',
            'received_at' => 'Received',
            'decision_note' => 'Decision reason',
            'payment' => 'Paid by',
            'sold' => 'Sold',
            'asked' => 'Asked',
            'approved' => 'Approved',
            'received' => 'Received',
            'disposition' => 'Disposition',
            'warehouse' => 'Warehouse',
            'amount' => 'Amount',
            'refund_request' => 'Refund request',
            'refund_note' => 'Note',
        ],
        'cod' => 'Cash on delivery',
        'online' => 'Online payment',
        'approve' => 'Approve',
        'approve_help' => 'Take back up to what was asked for. Set a line to 0 to refuse part of it.',
        'reject' => 'Reject',
        'reason' => 'Reason',
        'reason_help' => 'Recorded on the return and told to the customer. At least 10 characters.',
        'receive' => 'Record what arrived',
        'receive_help' => 'Count what actually arrived and say what happens to it. This changes stock.',
        'warehouse' => 'Warehouse it arrived at',
        'arrived' => 'Arrived',
        'note' => 'Note for staff',
        'start_refund' => 'Start the refund',
        'start_refund_help' => 'Works out what the goods that arrived are worth and opens the refund. Nothing is paid until the refund is decided and sent.',
        'approve_refund' => 'Approve the refund',
        'reject_refund' => 'Refuse the refund',
        'refund_note' => 'Why',
        'send_refund' => 'Send the refund',
        'send_refund_help' => 'Sends the approved refund to the gateway that took the payment. You will be asked to confirm your password.',
        'settle' => 'Record the manual refund',
        'settle_help' => 'Paid on delivery, so no gateway can send this money back. Say how it was settled — cash, transfer, receipt number. You will be asked to confirm your password.',
        'how' => 'How it was settled',
        'history' => [
            'system' => 'System',
            'reason' => 'Reason: :reason',
            'internal' => 'Staff note: :note',
        ],
        'refund_request_statuses' => [
            'requested' => 'Awaiting decision',
            'approved' => 'Approved, not yet sent',
            'rejected' => 'Refused',
            'processed' => 'Sent',
            'failed' => 'Failed at the gateway',
        ],
        'flash' => [
            'approved' => 'The return has been approved.',
            'rejected' => 'The return has been rejected.',
            'received' => 'The goods have been received and stock updated.',
            'refund_started' => 'The refund has been opened.',
            'refund_approved' => 'The refund has been approved.',
            'refund_rejected' => 'The refund has been refused.',
            'settled' => 'The manual refund has been recorded.',
        ],
        'refused' => [
            'refund_not_waiting' => 'This refund is not waiting for a decision.',
        ],
    ],
];
