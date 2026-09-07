<?php

return [
    /*
     * One entry per event written to the database channel (D20).
     *
     * The stored notification keeps only an `event` plus that event's own
     * fields, so the wording lives here rather than in the row. That is what
     * lets a notification sent in English read in Bangla when the recipient
     * switches language — and what lets us reword an alert without rewriting
     * history.
     *
     * A `description` is the generic fallback. Where an administrator wrote
     * instructions or feedback of their own, those are shown instead: their
     * words say what to do, this line only says what happened.
     */
    'events' => [
        'kyc.deadline_approaching' => [
            'title' => 'Your verification is due soon',
            'description' => 'Finish your verification before the deadline to keep your account active.',
        ],

        'kyc.deadline_missed' => [
            'title' => 'Your verification is overdue',
            'description' => 'Submit your verification documents to restore full access.',
        ],

        'kyc.deadline_restriction_lifted' => [
            'title' => 'Your account restriction has been lifted',
            'description' => 'Your verification is complete and the restriction has been removed.',
        ],

        'kyc.update_requested' => [
            'title' => 'We need updated verification documents',
            'description' => 'An administrator has asked for changes to your verification.',
        ],

        'account.kyc_resubmission_requested' => [
            'title' => 'Your verification needs a correction',
            'description' => 'Review the feedback and submit your documents again.',
        ],

        'account.activated' => [
            'title' => 'Your account is now active',
            'description' => 'You now have full access to your Feriwala account.',
        ],

        'account.suspended' => [
            'title' => 'Your account has been suspended',
            'description' => 'Contact support to find out what is needed to restore access.',
        ],
    ],
];
