<?php

return [
    'title' => 'SMS',
    'description' => 'Whether text messages go out, and which provider carries them.',
    'nav' => 'SMS',

    'saved' => 'SMS settings saved.',

    'switch' => [
        'title' => 'Sending',
        'description' => 'The global switch §30 requires. With this off nothing is sent, whatever any individual notification says.',
        'enabled' => 'Send text messages',
        'disabled_notice' => 'SMS is switched off. Messages are recorded as suppressed rather than queued.',
        'unavailable_notice' => 'The selected provider has no driver yet, so nothing can be sent.',
    ],

    'providers' => [
        'title' => 'Providers',
        'description' => 'Every provider §30.1 names. Real providers arrive in a later phase; the log provider records messages instead of sending them.',
        'active' => 'In use',
        'not_implemented' => 'Not built yet',
        'available' => 'Available',
        'choose' => 'Provider',
        'submit' => 'Save',
    ],
];
