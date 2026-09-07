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

];
