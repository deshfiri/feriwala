<?php

return [
    'title' => 'Billing rules',
    'description' => 'What Feriwala charges, and when each price applied.',
    'nav' => 'Billing rules',

    'forbidden_title' => 'You cannot manage billing rules',
    'forbidden_description' => 'Pricing fees, coupons and tax needs the payment settings permission. Ask an administrator if you need it.',

    'fees' => [
        'title' => 'Fee rules',
        'description' => 'A price is never edited. Changing a fee opens a new rule and closes the one it replaces, so past quotes still reproduce.',
        'add' => 'Add a fee rule',
        'type' => 'Fee',
        'package' => 'Package',
        'package_any' => 'All packages',
        'package_help' => 'A rule naming a package beats the global one.',
        'amount' => 'Amount (minor units)',
        'amount_help' => '50000 is ৳500.00.',
        'effective_from' => 'In force from',
        'effective_until' => 'In force until',
        'effective_until_help' => 'Leave blank to keep it open.',
        'note' => 'Note',
        'note_help' => 'Why this price. Read by whoever finds it in two years.',
        'submit' => 'Add rule',
        'close' => 'Close',
        'created' => 'Fee rule added.',
        'closed' => 'Fee rule closed.',
        'in_force' => 'In force',
        'scheduled' => 'Scheduled',
        'ended' => 'Ended',
        'empty' => 'No fee rules yet. Without one, the registration fee is whatever each package sets, or nothing.',
    ],
];
