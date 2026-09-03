<?php

return [
    'queue' => [
        'title' => 'Activation approvals',
        'description' => 'Accounts that have met every condition and are waiting on us.',
        'search_placeholder' => 'Search by name, email, or mobile…',
        'caption' => 'Accounts awaiting activation approval',
        'empty_title' => 'Nothing waiting',
        'empty_description' => 'No account is currently ready for approval. New ones will appear here.',
        'open' => 'Review',
    ],

    'columns' => [
        'account' => 'Account',
        'country' => 'Country',
        'ready_since' => 'Ready since',
        'waiting' => 'Waiting',
        'status' => 'Status',
    ],

    'waiting' => [
        'today' => 'Today',
        'one_day' => '1 day',
        'days' => ':count days',
    ],

    'detail' => [
        'title' => 'Activation review',
        'account' => 'Account',
        'conditions' => 'Conditions',
        'conditions_help' => 'All three are required before an account can be activated.',
        'history' => 'Account history',
        'no_history' => 'Nothing has changed on this account yet.',
        'back_to_queue' => 'Back to queue',
        'registered_at' => 'Registered',
        'internal_note_label' => 'Internal note',
        'met' => 'Met',
        'unmet' => 'Not met',
    ],

    'decision' => [
        'title' => 'Decision',
        'description' => 'Recorded against your name. Activation is the point an account can begin trading.',
        'approve' => 'Approve and activate',
        'corrections' => 'Send back for corrections',
        'suspend' => 'Suspend this application',
        'not_ready' => 'This account does not meet every condition yet.',
        'not_permitted' => 'Your role does not include approving activations.',
        'note' => 'Note to the account holder (optional)',
        'reason' => 'Reason',
        'reason_help' => 'Kept internally, on the record of this decision.',
        'feedback' => 'What the applicant sees',
        'feedback_help' => 'Tell them what to correct — without it they will send the same thing again.',
        'internal_note' => 'Internal note (optional)',
        'submit' => 'Record decision',
        'suspend_warning' => 'Suspending is not something the applicant can undo.',
    ],
];
