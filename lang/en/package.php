<?php

return [
    'title' => 'Packages',
    'description' => 'What Feriwala sells, and what each plan grants.',
    'nav' => 'Packages',

    'empty_title' => 'No packages yet',
    'empty_description' => 'Nobody can be activated until there is a package to buy.',
    'forbidden_title' => 'You cannot manage packages',
    'forbidden_description' => 'Managing packages needs the package permission. Ask an administrator if you need it.',

    'add' => 'Add package',
    'archived_heading' => 'Archived',
    'archived_description' => 'Withdrawn plans, kept because subscriptions, payments and invoices still name them.',

    'state' => [
        'active' => 'On sale',
        'paused' => 'Not on sale',
        'archived' => 'Archived',
        'public' => 'Listed publicly',
        'private' => 'Not listed',
    ],

    'actions' => [
        'edit' => 'Edit',
        'pause' => 'Take off sale',
        'resume' => 'Put on sale',
        'archive' => 'Archive',
        'cancel' => 'Cancel',
        'save' => 'Save package',
    ],

    'meta' => [
        'fee' => 'Package fee',
        'subscriptions' => ':count account(s) subscribed',
        'validity' => 'Valid :days days',
        'no_expiry' => 'No expiry',
    ],

    'blockers' => [
        'heading' => 'Cannot be archived yet',
        'kyc' => 'Named by verification requirements: :names',
        'subscriptions' => ':count account(s) are still subscribed.',
        'help' => 'Change those first. Archiving without them would leave rules that match nobody.',
    ],

    'form' => [
        'create_title' => 'New package',
        'edit_title' => 'Edit package',
        'description' => 'Prices are in minor units — 50000 is ৳500.00.',

        'name' => 'Name',
        'slug' => 'Slug',
        'slug_help' => 'Lowercase letters, numbers and hyphens. Appears in URLs and in verification scope rules.',
        'short_description' => 'Short description',
        'full_description' => 'Full description',

        'pricing' => 'Pricing',
        'fee' => 'Package fee',
        'registration_fee' => 'Registration fee override',
        'registration_fee_help' => 'Leave blank to use the global registration fee.',
        'renewal_fee' => 'Renewal fee',
        'renewal_frequency' => 'Renewal frequency',
        'grace_period_days' => 'Grace period (days)',
        'validity_days' => 'Validity (days)',
        'currency_code' => 'Currency',

        'wallet' => 'Wallet',
        'required_deposit' => 'Required deposit',
        'minimum_balance' => 'Minimum balance',

        'availability' => 'Availability',
        'available_from' => 'On sale from',
        'available_until' => 'On sale until',
        'is_active' => 'On sale',
        'is_public' => 'Listed on the public package page',

        'features' => 'Entitlements',
        'features_help' => 'Leave a limit blank for unlimited. Zero means none at all.',
        'charges' => 'Service charges',
        'charges_help' => 'Website setup, maintenance, domain and hosting (§16.2).',
        'add_charge' => 'Add a charge',
        'remove_charge' => 'Remove',
        'charge_type' => 'Charge',
        'charge_amount' => 'Amount',
        'charge_frequency' => 'Frequency',
    ],

    'features' => [
        'unlimited' => 'Unlimited',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'frequency' => [
        'once' => 'One-off',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ],

    'charge_type' => [
        'website_setup' => 'Website setup',
        'website_maintenance' => 'Website maintenance',
        'domain' => 'Domain',
        'hosting' => 'Hosting',
    ],
];
