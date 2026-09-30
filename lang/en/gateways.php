<?php

return [
    'title' => 'Payment gateways',
    'description' => 'Which providers can take a payment, and what each one still needs.',
    'nav' => 'Payment gateways',

    'saved' => 'Gateway settings saved.',
    'enabled' => 'Gateway switched on.',
    'disabled' => 'Gateway switched off.',

    'overview' => [
        'title' => 'All providers',
    ],

    'state' => [
        'available' => 'Taking payments',
        'not_configured' => 'Needs credentials',
        'disabled' => 'Switched off',
        'not_implemented' => 'Not built yet',
    ],

    'mode' => [
        'label' => 'Mode',
        'sandbox' => 'Sandbox',
        'live' => 'Live',
        'help' => 'Sandbox and live credentials are stored separately, so switching mode cannot pick up the wrong pair.',
    ],

    'capabilities' => 'What this provider can do',
    'capability' => [
        'initiate' => 'Take a payment',
        'verify' => 'Verify with the provider',
        'webhook_signature' => 'Signed webhooks',
        'status_query' => 'Status lookup',
        'refund_full' => 'Full refund',
        'refund_partial' => 'Partial refund',
        'refund_status' => 'Refund status',
    ],

    'currencies' => 'Currencies accepted',
    'last_verified' => 'Last confirmed a payment',
    'never_verified' => 'Never',
    'missing' => 'Still missing',

    'fields' => [
        'store_id' => 'Store ID',
        'store_password' => 'Store password',
        'merchant_id' => 'Merchant ID',
        'username' => 'Username',
        'password' => 'Password',
        'hash_key' => 'Hash key',
        'prefix' => 'Transaction prefix',
        'signature_key' => 'Signature key',
        'base_url' => 'Base URL',
        'app_key' => 'App key',
        'app_secret' => 'App secret',
        'merchant_number' => 'Merchant number',
        'merchant_private_key' => 'Merchant private key',
        'nagad_public_key' => 'Nagad public key',
        'secret_key' => 'Secret key',
        'webhook_secret' => 'Webhook signing secret',
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
        'webhook_id' => 'Webhook ID',
    ],

    'credentials' => [
        'description' => 'Stored encrypted. They are never shown again after saving — leave a field blank to keep what is already there.',
        'set' => 'Saved',
        'missing' => 'Not set',
        'blank_help' => 'Leave a field blank to keep the stored value.',
        'submit' => 'Save credentials',
    ],

    'enable' => [
        'help' => 'A gateway can only be switched on once every credential it needs is stored for the mode it is in.',
        'enable' => 'Switch on',
        'disable' => 'Switch off',
    ],

    'planned' => [
        'title' => 'Not built yet',
        'description' => 'These providers have somewhere to keep their credentials, but no confirmed protocol to use them with — their integration documentation is issued to merchants rather than published. They cannot take a payment and cannot be switched on.',
    ],

    'sandbox_notice' => 'This gateway is in sandbox mode. No real money moves.',
    'empty_help' => 'A gateway is offered at checkout only when it is switched on in configuration and has credentials.',

    'switches' => [
        'title' => 'Payments',
        'description' => 'Every payment gateway on one list. Switch a gateway on to offer it at checkout, or off to stop offering it.',
        'list_title' => 'Payment gateways',
        'list_description' => 'A gateway can only be switched on once its credentials are stored and it can confirm payments with its provider.',
        'payment_log' => 'Payment log',
        'credentials' => 'Gateway credentials',
        'toggle' => 'Offer :gateway at checkout',
        'read_only' => 'You can see which gateways are on, but switching them needs the permission to manage gateways.',
        'summary' => [
            'received' => 'Received through gateways',
            'received_hint' => 'Settled payments only — paid, or paid and partly refunded.',
            'payments' => 'Settled payments',
            'taking_payments' => 'Gateways taking payments',
            'of_total' => ':count of :total',
        ],
        'row' => [
            'received' => 'Received',
            'payments' => ':count settled payments',
            'last_paid' => 'last :date',
        ],
        'reason' => [
            'not_implemented' => 'Not built yet — it cannot take a payment.',
            'not_configured' => 'Needs credentials: :fields.',
            'not_configured_plain' => 'Needs credentials before it can be switched on.',
            'live' => 'Live mode — real money.',
            'sandbox' => 'Sandbox mode — no real money moves.',
        ],
    ],
];
