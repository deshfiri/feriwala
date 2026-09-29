<?php

return [
    'nav' => [
        'payout_methods' => 'Payout methods',
    ],

    'index' => [
        'title' => 'Payout methods',
        'description' => 'Where your withdrawals are paid. Only the last four digits are ever shown again after saving.',
        'empty_title' => 'No payout method yet',
        'empty_description' => 'Add a bank account, bKash or Nagad number before requesting a withdrawal.',
        'add' => 'Add payout method',
        'edit' => 'Edit',
        'archive' => 'Archive',
        'archive_confirm' => 'Archive this payout method? It stays on any withdrawal already made against it, but cannot be selected for a new one.',
        'default' => 'Default',
        'make_default' => 'Make default',
        'type' => 'Type',
        'label' => 'Label',
        'masked_number' => 'Account number',
        'bank_and_branch' => 'Bank / branch',
        'status' => 'Status',
        'verified' => 'Verified',
        'not_verified' => 'Not yet verified',
    ],

    'form' => [
        'current_password' => 'Current password',
        'current_password_help' => 'Confirms this change is really you.',
        'save' => 'Save payout method',
        'missing_fields' => 'These details are required: :fields',
        'account_number_mismatch' => 'Account number and confirmation do not match.',
        'invalid_bank_branch' => 'Choose a bank and branch from the list.',
        'fields' => [
            'bank' => 'Bank',
            'district' => 'District',
            'branch' => 'Branch',
            'account_holder_name' => 'Account holder name',
            'account_number' => 'Account number',
            'confirm_account_number' => 'Confirm account number',
            'account_type' => 'Account type',
        ],
        'account_types' => [
            'savings' => 'Savings',
            'current' => 'Current',
        ],
    ],

    'flash' => [
        'created' => 'Payout method saved.',
        'updated' => 'Payout method updated.',
        'default_set' => 'Default payout method updated.',
        'archived' => 'Payout method archived.',
    ],
];
