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

    'subscription' => [
        'title' => 'Your package',
        'description' => 'What you are on, what it grants, and when it renews.',
        'nav' => 'Package',

        'none_title' => 'No package yet',
        'none_description' => 'Choose one to continue setting up your account.',
        'choose' => 'Choose a package',

        'current' => 'Current term',
        'history' => 'Previous terms',
        'no_history' => 'Nothing yet. Terms appear here as they end.',

        'started' => 'Started',
        'expires' => 'Ends',
        'grace_ends' => 'Grace period ends',
        'cancelled' => 'Cancelled',
        'no_expiry' => 'Does not expire',
        'days_left' => ':count days left',
        'ends_today' => 'Ends today',
        'ended' => 'Ended :date',
        'in_grace' => 'Inside the grace period — renew to restore full access.',

        'paid' => 'Paid',
        'renewal' => 'Renews at',
        'renewal_frequency' => 'Renews :frequency',
        'source' => 'How you got it',

        'entitlements' => 'What this includes',
        'unlimited' => 'Unlimited',
        'included' => 'Included',
        'not_included' => 'Not included',
        'none' => 'None',
    ],

    'renewal' => [
        'title' => 'Renew your package',
        'description' => 'Carry on with the same plan for another term.',
        'action' => 'Renew',
        'line' => ':package package renewal',
        'summary' => 'What you are renewing',
        'starts' => 'New term starts',
        'starts_help' => 'Your current term runs to the end. Renewing early costs you no days.',
        'runs_for' => 'Runs for :days days',
        'pay' => 'Pay and renew',
        'total' => 'Total payable',
        'nothing_to_pay' => 'There is nothing to pay to renew this package.',
        'free' => 'This renewal costs nothing.',
        'terms_unavailable' => 'This package is no longer available to renew. Choose another.',

        'no_subscription' => 'You do not have a package to renew yet.',
        'awaiting_first_payment' => 'Finish paying for your package before renewing it.',
        'term_closed' => 'This term has closed. Choose a package to start a new one.',
        'no_expiry' => 'This package does not expire, so there is nothing to renew.',
        'too_early' => 'You can renew within :days days of your term ending.',
    ],

    'change' => [
        'title' => 'Change your package',
        'description' => 'Move to another plan. Upgrades apply now; smaller plans start when your current term ends.',
        'action' => 'Change package',
        'line' => ':package package',
        'credit' => 'Credit for the rest of your current term',
        'deposit' => 'Additional wallet deposit',
        'effective' => 'Applies from',
        'payable' => 'Payable now',
        'choose' => 'Choose this plan',
        'nothing_to_pay' => 'There is nothing to pay for that change.',
        'blocked' => 'You are over this plan\'s limits',
        'must_remove' => ':label — you have :current, this plan allows :limit. Remove :count.',
        'current' => 'Your current plan',
        'none' => 'No other plans are available right now.',
    ],

    'assign' => [
        'title' => 'Assign a package',
        'description' => 'Give this account a plan without a sale. No payment is created.',
        'action' => 'Assign a package',
        'package' => 'Package',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail. Not shown to the account.',
        'starts_at' => 'Applies from',
        'expires_at' => 'Ends',
        'expires_help' => 'Leave blank for a term that does not expire.',
        'promotional' => 'Record this as promotional rather than a manual assignment',
        'submit' => 'Assign',
        'done' => 'Package assigned.',
        'warning' => 'This replaces the account\'s current term and grants real entitlement for nothing.',
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
