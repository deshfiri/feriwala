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
        'description' => 'These providers are named in the specification but have no driver, so they cannot hold credentials.',
    ],

    'sandbox_notice' => 'This gateway is in sandbox mode. No real money moves.',
    'empty_help' => 'A gateway is offered at checkout only when it is switched on in configuration and has credentials.',
];
