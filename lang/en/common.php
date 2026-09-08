<?php

return [
    'actions' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'confirm' => 'Confirm',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'create' => 'Create',
        'search' => 'Search',
        'filter' => 'Filter',
        'export' => 'Export',
        'retry' => 'Try again',
        'back' => 'Back',
        'next' => 'Next',
    ],

    'states' => [
        'loading' => 'Loading…',
        'empty_title' => 'Nothing here yet',
        'empty_description' => 'When there is something to show, it will appear here.',
        'error_title' => 'Something went wrong',
        'error_description' => 'We could not load this. Try again, and contact support if it keeps happening.',
        'forbidden_title' => 'You do not have access to this',
        'forbidden_description' => 'Your role does not include permission for this page. Ask an administrator if you need it.',
        'offline_title' => 'You appear to be offline',
        'offline_description' => 'Check your connection and try again.',
    ],

    'table' => [
        'search_placeholder' => 'Search…',
        'columns' => 'Columns',
        'rows_selected' => ':count selected',
        'no_results' => 'No results match your filters.',
        'showing' => 'Showing :from–:to of :total',
        'per_page' => 'Per page',
    ],

    'language' => [
        'label' => 'Language',
        'switch' => 'Change language',
    ],

    'verify_email' => [
        'title' => 'Confirm your email address',
        'description' => 'We sent a link to :email. Open it to finish setting up your account.',
        'why' => 'Until it is confirmed we cannot reach you about payments, verification or your orders.',
        'not_arrived' => 'Nothing arrived? Check your spam folder, then send it again.',
        'resend' => 'Send it again',
        'sending' => 'Sending…',
        'sent' => 'Sent. Check your inbox for the new link.',
        'throttled' => 'You have asked for several links in a short time. Wait a minute and try again.',
        'wrong_address' => 'Wrong address? Sign out and register again.',
        'sign_out' => 'Sign out',
    ],

    'settings' => [
        'title' => 'Settings',
        'description' => 'Manage your profile and account settings',
        'nav' => [
            'label' => 'Settings sections',
            'profile' => 'Profile',
            'security' => 'Security',
            'appearance' => 'Appearance',
            'package' => 'Package',
            'staff' => 'Staff',
        ],
    ],

    'appearance' => [
        'label' => 'Appearance',
        // Stored per browser, not on the account, so say so rather than let
        // someone wonder why their phone did not change with their laptop.
        'description' => 'Choose how Feriwala looks on this device.',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
        'system_hint' => 'Follows your device setting.',
    ],
];
