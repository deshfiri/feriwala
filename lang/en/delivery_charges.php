<?php

return [
    'title' => 'Delivery charges',
    'description' => 'The delivery charge added to every order. Changes apply to new orders only.',

    'guide' => [
        'title' => 'How delivery charges work',
        'areas' => 'Customer orders pay one of two delivery charges, chosen by the delivery address\'s district: Inside Dhaka or Outside Dhaka.',
        'dhaka' => '"Inside Dhaka" means Dhaka district only (Dhaka North and South City Corporations and the rest of Dhaka district). Other districts of Dhaka division — Gazipur, Narayanganj, Manikganj and so on — are Outside Dhaka.',
        'per_order' => 'One delivery charge per order — not per product, not per quantity. An order with five products pays it once.',
        'everyone' => 'Both charges are the same for every partner and every subscription plan.',
        'free' => 'Set a charge to 0 for free delivery. The customer sees "Free delivery".',
        'missing' => 'If a charge is not set, customers cannot place orders to that area until it is. No order is ever sent out charging nothing by mistake.',
        'frozen' => 'The charge is calculated by the server and saved on the order when it is placed. Changing it here never changes an existing order.',
        'tax' => 'Tax on the delivery charge follows your tax rules (Admin → Billing → Tax). The preview shows what applies now.',
        'wholesale' => 'Wholesale purchases (a partner buying for themselves) have their own single delivery charge, below.',
    ],

    'rates' => [
        'inside_dhaka' => [
            'title' => 'Inside Dhaka',
            'description' => 'Customer orders delivered to an address in Dhaka district.',
        ],
        'outside_dhaka' => [
            'title' => 'Outside Dhaka',
            'description' => 'Customer orders delivered to any other district of Bangladesh.',
        ],
        'wholesale' => [
            'title' => 'Wholesale purchases',
            'description' => 'A partner buying products for themselves from the ERP. One charge per order.',
        ],
    ],

    'groups' => [
        'customer' => 'Customer orders (storefront and orders a partner enters by hand)',
        'wholesale' => 'Wholesale',
    ],

    'per_order' => 'per order',
    'free' => 'Free delivery',
    'not_set' => 'Not set',
    'active' => 'Active',
    'since' => 'Since :date',
    'customer_not_ready' => 'Customers cannot check out until both the Inside Dhaka and Outside Dhaka delivery charges are set.',

    'actions' => [
        'set' => 'Set charge',
        'change' => 'Change',
        'deactivate' => 'Deactivate',
        'deactivate_confirm' => 'Deactivate this delivery charge? Customers will not be able to order to this area until a new charge is set.',
    ],

    'form' => [
        'amount' => 'Delivery charge per order',
        'amount_help' => 'In Taka. Enter 0 for free delivery.',
        'note' => 'Note',
        'note_help' => 'Optional. Recorded in the audit log.',
        'save' => 'Save',
    ],

    'preview' => [
        'title' => 'Preview',
        'subtotal' => 'Order subtotal',
        'delivery' => 'Delivery charge',
        'delivery_tax' => 'Delivery tax (:label)',
        'delivery_tax_included' => 'Included in the delivery charge',
        'total' => 'Grand total',
    ],

    'flash' => [
        'saved' => 'Delivery charge saved. It applies to new orders.',
        'deactivated' => 'Delivery charge deactivated.',
    ],
];
