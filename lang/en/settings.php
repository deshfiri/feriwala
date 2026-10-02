<?php

return [
    /*
     * The permission-filtered settings hub (commit-order item 4) -- a
     * navigational index, not a settings screen of its own.
     */
    'hub' => [
        'title' => 'Settings',
        'description' => 'Every platform-wide configuration screen you can reach, in one place.',
        'empty_title' => 'Nothing to configure here yet',
        'empty_description' => 'None of your roles carry a settings permission.',

        'sections' => [
            'money' => 'Money & payments',
            'platform' => 'Platform policy',
            'communication' => 'Communication & branding',
            'integrations' => 'Integrations',
        ],

        'items' => [
            'billing_rules' => 'Fee rules, coupons and tax on every invoice.',
            'payment_gateways' => 'Which gateways are enabled and where the money lands.',
            'payments' => 'Switch each payment gateway on or off, and open the payment log.',
            'deposit_rules' => 'What accounts are required to deposit and keep.',
            'withdrawal_limits' => 'Minimum and maximum withdrawal amounts, and per-account overrides.',
            'website_pricing' => 'What partners may charge for what they sell.',
            'referral_settings' => 'The multi-level referral programme configuration.',
            'packages' => 'The subscription packages accounts can choose from.',
            'kyc_requirements' => 'The verification documents a business is asked for.',
            'sms' => 'The SMS gateway and which notifications it sends.',
            'branding' => 'The platform logo and browser icon.',
            'storage' => 'Where uploaded files are kept, and the Cloudflare R2 connection.',
        ],
    ],
];
