<?php

return [
    'title' => 'Storage (Cloudflare R2)',
    'description' => 'Where uploaded files are kept once R2 is switched on. Credentials are stored encrypted and never shown again after saving.',
    'nav' => 'Storage settings',

    'status_title' => 'Status',
    'state' => [
        'enabled' => 'Switched on',
        'configured' => 'Configured, not switched on',
        'not_configured' => 'Not configured',
    ],

    'not_set' => 'Not set',
    'set' => 'Saved',
    'blank_keeps_existing' => 'Leave blank to keep the stored value.',

    'fields' => [
        'account_id' => 'Account ID',
        'access_key_id' => 'Access key ID',
        'secret_access_key' => 'Secret access key',
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
        'region' => 'Region',
        'public_domain' => 'Public domain',
        'default_visibility' => 'Default visibility',
        'signed_url_expiry_minutes' => 'Signed URL expiry (minutes)',
    ],

    'public_domain_help' => 'Only used for files stored with public visibility. Leave blank if none is set up yet.',
    'signed_url_expiry_help' => 'How long a signed link to a private file stays valid.',

    'visibility' => [
        'private' => 'Private (signed links only)',
        'public' => 'Public',
    ],

    'credentials_title' => 'Credentials',
    'credentials_description' => 'Tested against R2 before anything is saved. If the test fails, nothing here is changed.',

    'reason' => 'Reason',
    'reason_placeholder' => 'Why this change is being made',

    'test_connection' => 'Test connection',
    'testing' => 'Testing…',
    'test_succeeded' => 'Connected to the bucket successfully.',
    'test_failed_generic' => 'Could not reach R2 with these settings.',

    'enable' => 'Switch on',
    'disable' => 'Switch off',

    'saved' => 'Storage settings saved.',
    'enabled' => 'Cloudflare R2 switched on.',
    'disabled' => 'Cloudflare R2 switched off.',
];
