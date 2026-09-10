<?php

return [
    'title' => 'Payment gateways',
    'description' => 'Which providers can take a payment, and what each one still needs.',
    'nav' => 'Payment gateways',

    'saved' => 'Gateway settings saved.',

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

    'credentials' => [
        'title' => 'SSLCommerz credentials',
        'description' => 'Stored encrypted. They are never shown again after saving — leave a field blank to keep what is already there.',
        'store_id' => 'Store ID',
        'store_password' => 'Store password',
        'set' => 'Saved',
        'missing' => 'Not set',
        'blank_help' => 'Leave blank to keep the stored value.',
        'submit' => 'Save credentials',
    ],

    'sandbox_notice' => 'This gateway is in sandbox mode. No real money moves.',
    'empty_help' => 'A gateway is offered at checkout only when it is switched on in configuration and has credentials.',
];
