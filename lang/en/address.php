<?php

return [
    'nav' => [
        'title' => 'Addresses',
    ],

    'client' => [
        'title' => 'Addresses',
        'description' => 'Your business and operational addresses.',
        'empty_title' => 'No addresses yet',
        'empty_description' => 'Add a business or operational address to get started.',
    ],

    'supplier' => [
        'title' => 'Addresses',
        'description' => 'Your registered, pickup and return addresses.',
        'empty_title' => 'No addresses yet',
        'empty_description' => 'Add a registered, pickup or return address to get started.',
    ],

    'types' => [
        'business' => 'Business address',
        'operational' => 'Operational address',
        'registered' => 'Registered address',
        'pickup' => 'Pickup address',
        'return' => 'Return address',
    ],

    'fields' => [
        'type' => 'Address type',
        'contact_name' => 'Contact name',
        'contact_mobile' => 'Contact mobile',
        'division' => 'Division',
        'district' => 'District',
        'upazila' => 'Thana / Upazila',
        'union' => 'Union',
        'detailed_address' => 'Detailed address',
        'landmark' => 'Landmark',
        'postcode' => 'Postcode',
    ],

    'select' => [
        'placeholder' => 'Select…',
        'none' => 'None',
    ],

    'form_description' => 'Fill in the address details below.',

    'actions' => [
        'add' => 'Add address',
        'edit' => 'Edit',
        'archive' => 'Archive',
        'set_default' => 'Set as default',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],

    'status' => [
        'default' => 'Default',
        'archived' => 'Archived',
    ],

    'archive_confirm' => 'Archive this address? It will no longer be usable or shown as default.',

    'flash' => [
        'created' => 'Address added.',
        'updated' => 'Address updated.',
        'default_set' => 'Default address updated.',
        'archived' => 'Address archived.',
    ],
];
