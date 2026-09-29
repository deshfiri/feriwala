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
        'back' => 'Back to staff',
        'effective_permissions' => 'Effective permissions',
        'change_role' => 'Change role',
        'current_role' => 'Current role',
        'new_role' => 'New role',
        'reason' => 'Reason for the change',
        'reason_help' => 'Recorded in the audit trail. Not shown to the person.',
        'confirm_password' => 'Confirm your password',
        'submit' => 'Assign role',
        'role_assigned' => 'Role updated.',
    ],
];
