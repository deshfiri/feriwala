<?php

return [
    'queue' => [
        'title' => 'KYC review',
        'description' => 'Applications waiting for a decision, oldest first.',
        'search_placeholder' => 'Search by name, email, or mobile…',
        'caption' => 'KYC submissions awaiting review',
        'empty_title' => 'Nothing waiting',
        'empty_description' => 'Every submission has been decided. New ones will appear here.',
        'all_statuses' => 'All waiting',
        'open' => 'Open',
    ],

    'columns' => [
        'applicant' => 'Applicant',
        'country' => 'Country',
        'round' => 'Round',
        'submitted' => 'Submitted',
        'waiting' => 'Waiting',
        'status' => 'Status',
    ],

    // Separate keys rather than Laravel's `|` plural form: the client-side
    // `t()` interpolates but does not pluralise, so a pipe would render raw.
    'waiting' => [
        'today' => 'Today',
        'one_day' => '1 day',
        'days' => ':count days',
    ],

    'detail' => [
        'title' => 'Submission :round',
        'applicant' => 'Applicant',
        'documents' => 'Documents',
        'fields' => 'Details provided',
        'history' => 'Review history',
        'no_documents' => 'No documents were attached to this round.',
        'no_fields' => 'No typed details were provided.',
        'no_history' => 'No decision has been recorded yet.',
        'documents_hidden' => 'Your role does not include opening KYC documents.',
        'view' => 'View',
        'download' => 'Download',
        'back_to_queue' => 'Back to queue',
        'submitted_at' => 'Submitted',
        'internal_note_label' => 'Internal note',
    ],

    'decision' => [
        'title' => 'Decision',
        'description' => 'Recorded against your name and cannot be edited afterwards.',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'resubmit' => 'Request resubmission',
        'reason' => 'Reason',
        'reason_help' => 'Kept internally, on the record of this decision.',
        'feedback' => 'What the applicant sees',
        'feedback_help' => 'Tell them what to fix — without it they will send the same thing again.',
        'internal_note' => 'Internal note (optional)',
        'submit' => 'Record decision',
        'already_decided' => 'This round has already been decided.',
        'not_permitted' => 'Your role does not include deciding KYC applications.',
    ],
];
