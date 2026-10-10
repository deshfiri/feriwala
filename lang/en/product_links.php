<?php

return [
    'linked' => ':name is now linked as the same Product.',
    'unlinked' => 'The link was removed.',
    'variants_matched' => 'The variations are now matched.',
    'variants_unmatched' => 'The variation match was removed.',
    'reason_label' => 'Reason (optional)',

    'errors' => [
        'not_found' => 'That Product could not be found.',
        'variant_not_found' => 'That variation could not be found.',
    ],

    'summary' => [
        'bpc' => 'BPC',
        'no_variants' => 'No variations',
        'variants' => ':count variation(s): :labels',
        'sources' => ':suppliers Supplier source(s) · :warehouses Warehouse source(s)',
    ],

    'section' => [
        'title' => 'Linked Products',
        'description' => 'Products confirmed by staff as the same physical Product. Each one stays an independent record; none is the master.',
        'link' => 'Link Same Product',
        'current' => 'This Product',
        'direct' => 'Directly linked',
        'none' => 'Not linked to any other Product.',
        'indirect' => 'Also in this network',
        'indirect_help' => 'Connected through the Products above. To disconnect one of these, remove the direct link that joins it.',
        'indirect_badge' => 'Indirect · :count links away',
        'direct_badge' => 'Direct',
        'view' => 'View',
        'unlink' => 'Unlink',
        'linked_by' => 'Linked by :name on :date',
    ],

    'search' => [
        'title' => 'Find the same Product',
        'description' => 'Search by BPC, title, SKU or barcode. Nothing is linked until you confirm it.',
        'placeholder' => 'BPC, title, SKU or barcode',
        'hint' => 'Type at least 2 characters.',
        'searching' => 'Searching…',
        'none' => 'No matching Products.',
        'failed' => 'The search failed. Try again.',
        'select' => 'Select',
        'picked' => 'Selected',
    ],

    'confirm_link' => [
        'title' => 'Link as the same Product?',
        'description' => 'You are confirming this is the same physical Product as the one you are viewing. Orders for either can then be fulfilled from the other\'s Suppliers and Warehouses.',
        'confirm' => 'Link Same Product',
    ],

    'confirm_unlink' => [
        'title' => 'Remove this link?',
        'description' => 'The two Products stop being the same Product, and so does anything connected only through this link. Allocations already made are not changed.',
        'confirm' => 'Unlink',
    ],

    'variants' => [
        'title' => 'Variation matches',
        'help' => 'Variations are never matched by their names. A variation is only a substitute once you match it here.',
        'none' => 'No variations matched yet, so none of this Product\'s variations will be offered as substitutes.',
        'product_itself' => 'The Product itself',
        'this_product' => 'This Product',
        'linked_product' => 'Linked Product',
        'choose' => 'Choose…',
        'match' => 'Match',
        'remove' => 'Remove match',
    ],

    'connect' => [
        'choose' => 'Choose an existing Product',
        'change' => 'Change',
        'clear' => 'Clear',
        'connected' => 'Connected to this Product',
        'locked_help' => 'This listing is already connected, so it will not be asked again. New approvals use this Product.',
        'panel_title' => 'Choose a Product',
        'panel_description' => 'Pick the Product this listing connects to. Search by BPC, title, SKU or barcode.',
    ],

    'picker' => [
        'panel_title' => 'Link with existing Products',
        'panel_description' => 'Pick every Product that is the same physical Product. Nothing is linked until you publish the decision.',
        'heading' => 'Same Product as an existing one?',
        'help' => 'Optional. Link this Product with existing Products you have confirmed are the same physical Product. Nothing is selected for you.',
        'unique' => 'Keep as a unique Product — not linked to any other.',
        'add' => 'Link an existing Product',
        'remove' => 'Remove :name',
    ],
];
