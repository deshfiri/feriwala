<?php

return [
    'title' => 'SMS',
    'description' => 'Whether text messages go out, and which provider carries them.',
    'nav' => 'SMS',

    'saved' => 'SMS settings saved.',

    'switch' => [
        'title' => 'Sending',
        'description' => 'The global switch §30 requires. With this off nothing is sent, whatever any individual notification says.',
        'enabled' => 'Send text messages',
        'disabled_notice' => 'SMS is switched off. Messages are recorded as suppressed rather than queued.',
        'unavailable_notice' => 'The selected provider has no driver yet, so nothing can be sent.',
    ],

    'providers' => [
        'title' => 'Providers',
        'description' => 'Every provider §30.1 names. Real providers arrive in a later phase; the log provider records messages instead of sending them.',
        'active' => 'In use',
        'not_implemented' => 'Not built yet',
        'available' => 'Available',
        'choose' => 'Provider',
        'submit' => 'Save',
    ],

    /*
     * Message bodies (§30.2). Kept short on purpose: Bangla is encoded as UCS-2,
     * so a segment is 70 characters rather than 160, and a template that reads
     * as one message in English can quietly cost three in Bangla.
     */
    'templates' => [
        'mobile_verification' => 'Your Feriwala verification code is :code. It expires in 5 minutes. Do not share it with anyone.',
        'kyc_deadline_missed' => 'Your Feriwala verification deadline has passed. Send your documents to restore your account.',
        'account_activated' => 'Your Feriwala account is now active. You can sign in and start trading.',
        'payment_received' => 'Feriwala received your payment of :amount. Reference :reference.',
        'payment_received_subject' => 'We received your payment',
    ],

    'history' => [
        'title' => 'Recent messages',
        'description' => 'Delivery status, and every message that failed (§30.2).',
        'empty' => 'Nothing has been sent yet.',
        'event' => 'Event',
        'recipient' => 'To',
        'status' => 'Status',
        'segments' => ':count segment|:count segments',
        'attempts' => ':count attempt|:count attempts',
    ],
];
