<?php

return [
    'created' => 'Account created. A password-setup link has been sent to the owner.',
    'link_sent' => 'A new password-setup link was sent. Any earlier link no longer works.',
    'link_revoked' => 'The password-setup link was revoked.',
    'verified' => 'Confirmed by staff. The person has been notified.',
    'channels' => ['email' => 'email address', 'mobile' => 'mobile number'],
    'verified_mail' => [
        'subject' => 'Your contact detail was confirmed',
        'line' => 'A member of our team confirmed your :channel for you.',
    ],

    'partner' => [
        'title' => 'Add Client/Partner',
        'description' => 'Open an account on someone\'s behalf. The owner sets their own password from an emailed link; you never see or choose one.',
    ],
    'supplier' => [
        'title' => 'Add Supplier',
        'description' => 'Open a Supplier account on their behalf. The Supplier sets their own password from an emailed link.',
    ],

    'fields' => [
        'name' => 'Owner name',
        'business_name' => 'Business name',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'country' => 'Country',
        'referral_code' => 'Partner code',
        'referral_help' => 'Optional. Only an active account\'s code is accepted.',
        'contact_person_name' => 'Contact person',
        'business_address' => 'Business address',
        'trade_licence_number' => 'Trade licence number',
        'tax_identification_number' => 'Tax identification number (TIN)',
        'reason' => 'Reason',
        'reason_help' => 'Why you are opening this account. Recorded in the audit log.',
    ],

    'contact' => [
        'title' => 'Email & mobile',
        'verified' => 'Verified',
        'by_staff' => 'Verified by staff',
        'not_verified' => 'Not verified',
        'confirm' => 'Confirm manually',
        'confirm_title' => 'Confirm on their behalf',
        'confirm_help' => 'Use this to clear an onboarding lockout (a code that never arrives, a filtered link). It is recorded as staff-verified, with your name and reason, and the person is told.',
        'setup_title' => 'Password-setup link',
        'setup_help' => 'Sending a new link makes any earlier one stop working. You never see or set their password.',
    ],
    'tabs' => [
        'overview' => 'Overview',
        'verification' => 'Verification & KYC',
        'billing' => 'Billing',
        'people' => 'People & access',
        'history' => 'History',
    ],
    'notice' => 'Email and mobile start unverified. Verify, collect payment and activate afterwards from the account workspace.',
    'submit' => 'Create and send setup link',
    'send_link' => 'Send setup link',
    'revoke_link' => 'Revoke setup link',
];
