<?php

return [
    'title' => 'Account verification',
    'description' => 'Which identity checks a newly registering owner must complete before their business can be activated.',
    'nav' => 'Account verification',
    'saved' => 'Verification settings saved.',

    'mobile' => [
        'title' => 'Mobile number verification',
        'description' => 'Required by default (§5.1). Switching this off removes the step entirely — nobody is left waiting on a code they cannot receive.',
        'enabled' => 'Require mobile number verification',
        'off_notice' => 'Mobile verification is switched off. New registrations skip this step and move straight to the next one.',
    ],

    // D39.
    'selfie' => [
        'title' => 'Selfie verification',
        'description' => 'Whether identity verification asks for a selfie for every partner. A single partner can be given their own setting from their activation review. Applies to rounds not yet submitted.',
        'label' => 'Selfie for every partner',
        'per_batch' => 'As set on each KYC template',
        'partner_label' => 'Selfie for this partner',
        'partner_default' => 'Follow the platform setting',
        'partner_saved' => 'Selfie setting for this partner saved.',
    ],

    'domains' => [
        'title' => 'Preferred domain extensions',
        'description' => 'The extensions a partner may choose their preferred domain from at business setup. There is no live availability check: staff check and buy the domain by hand during review.',
        'label' => 'Extensions offered',
        'help' => 'Separate with commas, e.g. .com, .shop, .store',
    ],
];
