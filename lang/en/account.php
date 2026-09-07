<?php

return [
    'detail' => [
        'title' => 'Business account',
        'back_to_queue' => 'Back to queue',

        'identity' => 'Identity and contact',
        'owner' => 'Owner',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'country' => 'Country',
        'registered_at' => 'Registered',
        'activated_at' => 'Activated',
        'identity_status' => 'Sign-in status',

        'verification' => 'Verification rounds',
        'verification_help' => 'Every round this account has been through, newest first. Rounds an administrator asked for show the reason and the instructions that were sent.',
        'no_verification' => 'This account has never submitted verification.',
        'round' => 'Round :number',
        'documents_count' => ':count uploaded',
        'asked_for' => 'Asked for',
        'submitted' => 'Submitted :date',
        'reviewed' => 'Reviewed :date',
        'due' => 'Due :date',
        'requested_by' => 'Requested by :name on :date',
        'internal_reason' => 'Internal reason',
        'instructions_sent' => 'Instructions sent',
        'required' => 'Required',
        'optional' => 'Optional',

        'subscription' => 'Package',
        'no_subscription' => 'This account is not on a package.',
        'payments' => 'Payments',
        'no_payments' => 'No payments yet.',
        'staff' => 'Staff',
        'no_staff' => 'No staff beyond the owner.',
        'status_history' => 'Status history',
        'no_status_history' => 'No status changes recorded.',
        'by_system' => 'System',
    ],

    'kyc_update' => [
        'action' => 'Request verification update',
        'title' => 'Request a verification update',
        'description' => 'Opens a new round and notifies the account owner. The account keeps trading while it is open.',

        'reason' => 'Internal reason',
        'reason_help' => 'Why we are asking. Recorded in the audit trail and never shown to the account holder.',
        'instructions' => 'Instructions for the account holder',
        'instructions_help' => 'What they need to do. Sent with the notification — "update your verification" on its own produces the same documents back.',

        'deadline' => 'Deadline',
        'deadline_help' => 'Leave empty to use the configured window.',

        'documents' => 'Documents to ask for',
        'documents_help' => 'Leave all selected to ask for everything this account is subject to.',
        'documents_none' => 'No document types apply to this account yet.',
        'select_all' => 'Select all',
        'clear_all' => 'Clear all',

        'confirm' => 'The owner will be notified as soon as you send this.',
        'submit' => 'Send request',
        'cancel' => 'Cancel',
        'sending' => 'Sending…',
        'unavailable' => 'Cannot request an update',
    ],
];
