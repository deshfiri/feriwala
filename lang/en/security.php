<?php

return [
    'sessions' => [
        'title' => 'Where you are signed in',
        'description' => 'Devices that have used this account. Sign out anything you do not recognise, then change your password.',
        'current' => 'This device',
        'unknown_address' => 'Address not recorded',
        'last_active' => 'Last active :when',
        'sign_out' => 'Sign out',
        'sign_out_others' => 'Sign out other devices',
        'sign_out_others_title' => 'Sign out every other device?',
        'sign_out_others_description' => 'Everything signed in to this account elsewhere will have to sign in again. This device stays signed in.',
        'empty' => 'Nothing else has used this account.',
        'ended' => [
            'signed_out' => 'Signed out',
            'revoked' => 'Signed out from here',
            'password_changed' => 'Ended when the password changed',
            'unknown' => 'Ended',
        ],
    ],

    'two_factor' => [
        'required_title' => 'Two-factor authentication is required for your role',
        'required_description' => 'Your role can reach money, personal documents or system settings, so it needs a second factor. Set it up below to continue.',
    ],

    'lock' => [
        'title' => 'Sign-in access',
        'description' => 'Whether these people can sign in at all. Separate from whether the business can trade.',
        'lock' => 'Lock sign-in',
        'unlock' => 'Restore sign-in',
        'lock_title' => 'Lock :name out of Feriwala?',
        'lock_description' => 'They lose every screen immediately, including administration, and any session they have open ends. The business account is not changed.',
        'unlock_title' => 'Restore sign-in for :name?',
        'unlock_description' => 'They will be able to sign in again straight away.',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail. Not shown to the person.',
        'reason_required' => 'A reason is required.',
        'owner' => 'Owner',
        'locked_because' => 'Locked :when',
        'no_permission' => 'You do not have permission to change sign-in access.',
    ],
];
