<?php

return [
    'title' => 'Product deletion',
    'description' => 'Choose which product statuses may be deleted. Deleted products go to Trash and can be restored.',
    'saved' => 'Product deletion setting saved.',

    'scope' => [
        'title' => 'Statuses that can be deleted',
        'description' => 'Applies to everyone who can delete products. Products that are not allowed to be deleted can still be archived.',
        'any_status' => 'All statuses',
        'any_status_help' => 'A product can be deleted whatever its status.',
        'drafts_only' => 'Draft only',
        'drafts_only_help' => 'Only draft products can be deleted. Anything pending review, active, inactive, out of stock, discontinued or archived cannot.',
    ],

    'drafts_only_notice' => 'Only draft products can be deleted right now.',
    'read_only' => 'You can view this setting but not change it.',
];
