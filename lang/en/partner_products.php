<?php

return [
    'title' => 'Products',
    'description' => 'Every product available to your account, in one place. A product that supports both wholesale and Partner Selling appears once.',
    'not_included_title' => 'Not included in your package',
    'not_included' => 'Your package includes neither wholesale purchasing nor Partner Selling.',

    'modes' => [
        'wholesale' => 'Wholesale',
        'partner_selling' => 'Partner Selling',
    ],

    'price' => [
        'wholesale' => 'Wholesale price',
        'suggested' => 'Suggested selling price',
        'minimum' => 'Minimum selling price',
        'maximum' => 'Maximum selling price',
        'not_set' => 'Not set',
    ],

    'details' => [
        'bpc' => 'BPC',
        'min_order' => 'Minimum order',
        'max_order' => 'Maximum order',
        'units' => ':count units',
        'tiers' => 'Quantity prices',
        'tier' => 'From :count: :amount',
        'variations' => 'Variations',
        'package' => 'Package eligibility',
        'package_all' => 'All packages',
        'package_selected' => 'Selected packages',
        'more' => 'Details',
    ],

    'tabs' => [
        'label' => 'Show products',
        'all' => 'All products',
        'mine' => 'My Products',
    ],
    'mine_empty_title' => 'You have not bought anything yet',
    'mine_empty_help' => 'Products appear here once a wholesale order for them is paid. Only these can be put on your website.',

    'actions' => [
        'order' => 'Order wholesale',
        'view' => 'View product',
        'reorder' => 'Order again',
        'in_my_products' => 'Bought — see My Products',
    ],

    'ownership' => [
        'purchased' => 'Bought',
        'available' => 'Available',
        'reserved' => 'Held for orders',
        'sold' => 'Sold',
        'unavailable' => 'Returned / damaged',
        'by_variation' => 'Stock by variation',
        'storefronts' => 'On your storefronts',
        'no_storefront' => 'You have no storefront yet. Request one to start selling.',
        'publish' => 'Put on sale',
        'unpublish' => 'Take off',
        'your_price' => 'Your selling price',
        'save_price' => 'Save price',
        'range' => 'Allowed: :min to :max',
        'no_range' => 'No limits set',
        'suggested' => 'Suggested :amount',
        'price_fixed' => 'The price on this product is set by Feriwala.',
        'state' => [
            'not_selected' => 'Not on this storefront',
            'selected' => 'Chosen, not on sale',
            'published' => 'On sale',
        ],
    ],
];
