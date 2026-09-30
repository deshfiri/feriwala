<?php

return [
    /*
     * The Roles & Permissions screens (commit-order item 6): browsing and
     * inspecting the twenty-one PlatformRole cases, and the platform staff
     * directory that assigns them.
     */
    'roles' => [
        'title' => 'Roles & permissions',
        'description' => 'The platform\'s fixed set of roles, plus any custom role your organisation has created.',
        'back' => 'Back to roles',
        'protected' => 'Protected',
        'custom' => 'Custom',
        'archived' => 'Archived',
        'requires_two_factor' => 'Requires two-factor',
        'permission_count' => ':count permissions',
        'holder_count' => ':count people hold this role',
        'permissions_heading' => 'Permissions',
        'holders_heading' => 'Who holds this role',
        'no_holders' => 'Nobody currently holds this role.',
        'grants_everything' => 'Grants every permission in the system, always — never listed as individual rows so it cannot drift out of step with the catalogue.',
        'manage_permissions' => 'Permission catalogue',

        'create' => 'Create role',
        'create_description' => 'A custom role your organisation controls — name it, describe it, and choose which permissions it carries.',
        'name' => 'Name',
        'name_help' => 'Lowercase letters, numbers and underscores only, e.g. "regional_manager".',
        'description' => 'Description',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail. Not shown to anyone holding the role.',
        'submit' => 'Save',
        'created' => 'Role created.',
        'updated' => 'Role updated.',

        'edit_permissions' => 'Edit role',
        'archived_notice' => 'This role is archived and can no longer be edited or assigned.',

        'clone' => 'Clone',
        'clone_title' => 'Clone this role',
        'clone_description' => 'Creates a new custom role starting from this one\'s permissions.',
        'cloned' => 'Role cloned.',

        'archive' => 'Archive',
        'archive_title' => 'Archive this role',
        'archive_description' => 'This role can no longer be assigned to anyone once archived. This cannot be undone.',
        'archived_flash' => 'Role archived.',
    ],

    /*
     * The permission catalogue screen (Role and Permission management):
     * every permission that exists, whether it is already checked
     * somewhere in the application (System-bound) or only named for future
     * use (Custom/unbound).
     */
    'permissions' => [
        'title' => 'Permission catalogue',
        'description' => 'Every permission the platform knows about, and which roles carry it.',
        'search_placeholder' => 'Search by name or description',
        'filters' => [
            'module' => 'Module',
            'type' => 'Type',
            'all_modules' => 'All modules',
            'all_types' => 'All types',
            'system' => 'System-bound',
            'custom' => 'Custom / unbound',
        ],
        'columns' => [
            'name' => 'Permission',
            'module' => 'Module',
            'action' => 'Action',
            'description' => 'Description',
            'type' => 'Type',
            'roles' => 'Roles',
        ],
        'system_bound' => 'System-bound',
        'custom_unbound' => 'Custom / unbound',
        'system_bound_help' => 'Already checked by a policy, Gate, route or action.',
        'custom_unbound_help' => 'Not yet wired into any check — assigning it grants no capability by itself.',
        'no_description' => 'No description',
        'roles_count' => ':count roles',

        'create' => 'Create permission',
        'create_description' => 'A custom permission for future use. Creating it does not protect anything by itself — that still needs a code change.',
        'name' => 'Name',
        'name_help' => 'Lowercase, dot-separated, e.g. "reporting.export_custom".',
        'description' => 'Description',
        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail.',
        'submit' => 'Save',
        'created' => 'Permission created.',

        'edit' => 'Edit',
        'edit_title' => 'Edit permission description',
        'updated' => 'Permission updated.',

        'archive' => 'Archive',
        'archive_title' => 'Archive this permission',
        'archive_description' => 'This permission can no longer be assigned to a role once archived. This cannot be undone.',
        'archived' => 'Permission archived.',

        'empty_title' => 'No permissions match',
        'empty_description' => 'Try a different search or filter.',
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
