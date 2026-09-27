<?php

/**
 * The staff directory of Client/Partner business accounts.
 *
 * "Partner" is not a second kind of account — it is a trading business using
 * wholesale, dropshipping or both — so the wording stays with "business"
 * throughout rather than inventing a parallel noun.
 */
return [
    'title' => 'Accounts',
    'description' => 'Every Client and Partner business registered on the platform.',
    'search_placeholder' => 'Account ID, business, owner, email or mobile',
    'open' => 'Open',
    'verified' => 'verified',
    'unverified' => 'not verified',
    'empty_title' => 'No accounts match',
    'empty_description' => 'Try a different search or clear the filters.',

    'summary' => [
        'kyc_pending' => 'KYC pending',
        'payment_pending' => 'Payment pending',
        'approval_pending' => 'Approval pending',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'expired' => 'Expired',
        'reverification_required' => 'Re-verification',
    ],

    'columns' => [
        'business' => 'Business',
        'contact' => 'Contact',
        'package' => 'Package',
        'status' => 'Status',
        'registered' => 'Registered',
        'activated' => 'Activated',
    ],

    'filters' => [
        'state' => 'Any state',
        'facility' => 'Any facility',
        'reverification' => 'Any re-verification',
        'package' => 'Any package',
        'wallet' => 'Any wallet state',
    ],

    'states' => [
        'trading' => 'Trading',
        'onboarding' => 'Onboarding',
        'halted' => 'Suspended or restricted',
        'expired' => 'Package expired',
        'closed' => 'Closed',
    ],

    'facilities' => [
        'wholesale' => 'Wholesale',
        'dropshipping' => 'Dropshipping',
        'both' => 'Wholesale and dropshipping',
    ],

    'reverification' => [
        'required' => 'Re-verification required',
        'overdue' => 'Re-verification overdue',
        'none' => 'No re-verification due',
    ],

    'wallet' => [
        'restricted' => 'Wallet restricted',
    ],
];
