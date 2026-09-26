<?php

return [
    'title' => 'Dashboard',

    'greeting' => [
        'morning' => 'Good morning, :name',
        'afternoon' => 'Good afternoon, :name',
        'evening' => 'Good evening, :name',
        'fallback' => 'Welcome back',
    ],

    'hero' => [
        'active' => 'Your account is active. Here is where your business stands today.',
        'needs_attention' => 'Something on your account needs looking at.',
    ],

    'actions' => [
        'renew_package' => 'Renew your package',
    ],

    'spend' => [
        'title' => 'What you have paid',
        'description' => 'Settled payments over the last :months months.',
        'series' => 'Paid',
        'total' => 'Total paid',
        'breakdown_title' => 'What it went on',
        'empty' => 'No payments yet. Charges appear here once a payment settles.',
        'breakdown_empty' => 'Nothing to break down yet.',
    ],

    'admin' => [
        'title' => 'Overview',
        'description' => 'What is waiting on you across the platform.',
        'empty' => 'Nothing is waiting on your permissions right now.',
        'hero' => [
            'attention' => ':count waiting across your queues.',
            'clear' => 'Nothing needs your attention right now.',
        ],
        'cards' => [
            'kyc' => 'KYC applications awaiting review',
            'activations' => 'Business activations pending',
            'orders' => 'Orders awaiting confirmation',
            'supplier_kyc' => 'Supplier applications awaiting review',
            'supplier_listings' => 'Supplier listings awaiting review',
        ],
        'trend' => [
            'orders' => 'Orders',
            'title' => 'Orders placed',
            'description' => 'The last :days days.',
            'empty' => 'No orders yet in this window.',
        ],
        'breakdown' => [
            'title' => 'What is waiting, by queue',
            'empty' => 'Nothing to break down yet.',
        ],
    ],

];
