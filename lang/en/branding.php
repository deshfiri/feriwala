<?php

return [
    'title' => 'Branding',
    'description' => 'The logo, browser icon and accent colour shown across the ERP, the sign-in pages and the public site.',

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

    'accent' => [
        'title' => 'Accent colour',
        'description' => 'Used for the current menu item, badges, focus rings and highlights across the ERP, Admin and Supplier panels. Buttons stay near-black.',
        'state_custom' => 'Custom',
        'presets' => 'Presets',
        'custom' => 'Custom colour',
        'hex' => 'Hex value',
        'hex_hint' => 'Six digits, for example #ca6330.',
        'preview' => 'Preview',
        'preview_focus' => 'Focused field',
        'preview_nav' => 'Current menu item',
        'preview_badge' => 'New',
        'low_contrast' => 'This colour is light: text drawn in it on a white card may be hard to read. A darker shade is easier on the eye.',
        'save' => 'Save colour',
        'restore' => 'Restore the default colour',
        'restore_confirm' => 'Go back to the default accent colour?',
        'saved' => 'Accent colour updated.',
        'restored' => 'Accent colour restored to the default.',
        'invalid' => 'Enter a six-digit hex colour, such as #ca6330.',
        'names' => [
            'crimson' => 'Crimson',
            'orange' => 'Orange',
            'amber' => 'Amber',
            'emerald' => 'Emerald',
            'teal' => 'Teal',
            'blue' => 'Blue',
            'indigo' => 'Indigo',
            'violet' => 'Violet',
            'slate' => 'Slate',
        ],
    ],
];
