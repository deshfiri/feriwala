<?php

return [
    'title' => 'Branding',
    'description' => 'The logo and browser icon shown across the ERP, the sign-in pages and the public site.',

    'logo' => [
        'title' => 'Logo',
        'description' => 'Shown in the sidebar, the header and the sign-in pages. A wide image on a transparent background works best.',
        'preview' => 'Current logo',
    ],

    'favicon' => [
        'title' => 'Browser icon',
        'description' => 'The small icon in browser tabs and bookmarks. A square image works best.',
        'preview' => 'Current browser icon',
    ],

    'state_custom' => 'Uploaded',
    'state_default' => 'Default',
    'file' => 'Image file',
    'help' => 'Accepted: :types, up to :size KB.',
    'upload' => 'Upload',
    'restore' => 'Restore the default',
    'restore_confirm' => 'Remove the uploaded image and go back to the default?',
    'saved' => ':asset updated.',
    'restored' => ':asset restored to the default.',
    'refused_type' => 'That file is not an accepted image. SVG files are not accepted.',
];
