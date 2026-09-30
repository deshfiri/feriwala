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
        'cod_confirmation' => 'Your code to confirm order :reference is :code. It expires in 5 minutes. Do not share it with anyone.',
        'kyc_deadline_missed' => 'Your Feriwala verification deadline has passed. Send your documents to restore your account.',
        'account_activated' => 'Your Feriwala account is now active. You can sign in and start trading.',
        'payment_received' => 'Feriwala received your payment of :amount. Reference :reference.',
        'payment_received_subject' => 'We received your payment',
    ],

    /*
     * Keyed by the raw event string, which contains dots — fetch the array
     * whole and index it (`SmsEvent::describe()`), never path into it.
     */
    'events' => [
        'account.activated' => [
            'title' => 'Account activated',
            'description' => 'Sent when a business account is approved and can start trading.',
        ],
        'payment.received' => [
            'title' => 'Payment received',
            'description' => 'Sent when a payment is confirmed, with its amount and reference.',
        ],
        'wallet.balance_low' => [
            'title' => 'Wallet balance low',
            'description' => 'Sent when a wallet drops below its low-balance threshold.',
        ],
        'kyc_deadline_missed' => [
            'title' => 'KYC deadline missed',
            'description' => 'Sent when an account misses its verification deadline.',
        ],
        'mobile_verification' => [
            'title' => 'Mobile verification code',
            'description' => 'The one-time code a customer enters to verify their mobile number.',
        ],
        'supplier_mobile_verification' => [
            'title' => 'Supplier mobile verification code',
            'description' => 'The one-time code a supplier enters to verify their mobile number.',
        ],
        'cod_confirmation' => [
            'title' => 'Cash on delivery confirmation code',
            'description' => 'The one-time code a buyer enters to confirm a cash-on-delivery order.',
        ],
    ],

    'event_switch' => [
        'title' => 'Messages by event',
        'description' => 'Turn text messages on or off for each event. Switching one off also stops its messages already waiting in the queue.',
        'toggle' => 'Send an SMS for :event',
        'code_warning' => 'One-time code. With this off, nobody receives it — they cannot finish verifying their mobile or confirm a cash-on-delivery order.',
        'confirm_code_off' => 'Switch off ":event"? Nobody will receive this code, so they will not be able to finish verifying their mobile or confirm a cash-on-delivery order until you switch it back on.',
        'code_off' => 'Verification codes by SMS are switched off right now, so no code can be sent. Please contact support.',
        'sent' => ':count sent',
        'global_off' => 'SMS is switched off above, so nothing is sent whatever these say.',
        'read_only' => 'You can see which events send SMS, but changing them needs the permission to manage SMS.',
        'enabled' => 'SMS for :event switched on.',
        'disabled' => 'SMS for :event switched off.',
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
