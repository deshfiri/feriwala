<?php

return [
    /*
     * The Roles & Permissions screens (commit-order item 6): browsing and
     * inspecting the twenty-one PlatformRole cases, and the platform staff
     * directory that assigns them.
     */
    'roles' => [
        'title' => 'Roles & permissions',
        'description' => 'The platform\'s fixed set of roles, and what each one grants.',
        'back' => 'Back to roles',
        'protected' => 'Protected',
        'requires_two_factor' => 'Requires two-factor',
        'permission_count' => ':count permissions',
        'holder_count' => ':count people hold this role',
        'permissions_heading' => 'Permissions',
        'holders_heading' => 'Who holds this role',
        'no_holders' => 'Nobody currently holds this role.',
        'grants_everything' => 'Grants every permission in the system, always — never listed as individual rows so it cannot drift out of step with the catalogue.',
    ],

    'staff' => [
        'title' => 'Platform staff',
        'description' => 'Everyone who administers Feriwala, and the role each one holds.',
        'search_placeholder' => 'Search by name or email',
        'empty_title' => 'No platform staff yet',
        'empty_description' => 'A platform staff login has no business account (D23).',
        'columns' => [
            'name' => 'Name',
            'role' => 'Role',
            'two_factor' => 'Two-factor',
            'status' => 'Sign-in',
        ],
        'no_role' => 'No role assigned',
        'two_factor_enabled' => 'Enabled',
        'two_factor_disabled' => 'Not set up',
        'back' => 'Back to staff',
        'manage' => 'Manage',
        'effective_permissions' => 'Effective permissions',

        'invite' => 'Invite staff',
        'invite_description' => 'Add a new platform staff login and set which roles it holds.',
        'name' => 'Name',
        'email' => 'Email',
        'mobile' => 'Mobile (optional)',
        'invited' => 'Staff member invited. They will receive an email to set their password.',
        'resend_invite' => 'Resend invite',
        'invite_resent' => 'Invitation resent.',

        'manage_roles' => 'Roles',
        'roles' => 'Roles',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail. Not shown to the person.',
        'confirm_password' => 'Confirm your password',
        'submit' => 'Save',
        'roles_updated' => 'Roles updated.',

        'sign_in_status' => 'Sign-in status',
        'activate' => 'Activate',
        'suspend' => 'Suspend',
        'deactivate' => 'Deactivate',
        'confirm_activate' => 'Restore this person\'s access to Feriwala.',
        'confirm_suspend' => 'Suspend this person\'s sign-in until it is restored.',
        'confirm_deactivate' => 'Permanently close this person\'s sign-in.',
        'deactivate_is_permanent' => 'This cannot be undone. A closed login can never be reactivated.',
        'cannot_reactivate_closed' => 'A closed sign-in cannot be reactivated.',
        'activated' => 'Sign-in activated.',
        'suspended' => 'Sign-in suspended.',
        'deactivated' => 'Sign-in permanently closed.',
    ],
];
