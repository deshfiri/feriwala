<?php

return [
    'assigned' => ':name now holds this Product.',
    'no_margin_warning' => 'Note: the base price is above the Product\'s wholesale price, so it leaves no margin.',
    'price_changed' => 'The Supplier Base Price was updated.',
    'assigned_by' => 'Assigned by :name on :date',
    'listed' => 'From the Supplier\'s approved listing',
    'updated' => 'Updated :date',

    'errors' => [
        'product_not_found' => 'That Product could not be found.',
        'supplier_not_found' => 'That Supplier could not be found.',
        'variant_not_found' => 'That variation could not be found.',
    ],

    'fields' => [
        'supplier' => 'Supplier',
        'variant' => 'Variation',
        'base_price' => 'Supplier Base Price',
        'supply_mode' => 'Supply mode',
        'quantity' => 'Available quantity',
        'capacity_label' => 'Fulfilment capacity (optional)',
        'capacity' => 'Capacity :count',
        'availability' => 'Stock / capacity',
        'lead_time' => 'Lead time (days)',
        'days' => ':count day(s)',
        'reason' => 'Reason (optional)',
        'reason_required' => 'Reason (required)',
    ],

    'modes' => [
        'ready_stock' => 'Ready stock',
        'on_demand' => 'On demand',
        'pre_order' => 'Pre-order',
    ],

    'section' => [
        'title' => 'Suppliers',
        'description' => 'The Suppliers that hold this Product and what each is paid per unit. Prices, stock and approval are the Supplier offer\'s own; the Product\'s platform prices are not changed here.',
        'assign' => 'Assign to Supplier',
        'none' => 'No Supplier holds this Product yet.',
        'view' => 'View supplier',
        'view_product' => 'View product',
        'edit_price' => 'Edit price',
        'suspend' => 'Suspend',
        'reactivate' => 'Reactivate',
    ],

    'assign_dialog' => [
        'title' => 'Assign to Supplier',
        'description' => 'Choose an approved Supplier and the price payable to them for one unit. This becomes the Supplier\'s offer on this Product.',
        'none' => 'No Supplier chosen',
        'choose' => 'Choose supplier',
        'use_supplier' => 'Use this supplier',
        'pick_variant' => 'Choose a variation',
        'price_help' => 'Payable to the Supplier per unit, in Taka. A price above the Product\'s wholesale price is allowed, with a margin warning.',
        'confirm' => 'Assign',
    ],

    'price_dialog' => [
        'title' => 'Change Supplier Base Price',
        'description' => 'A new price version is recorded. Allocations and payables already made keep the price they were made at.',
        'confirm' => 'Save price',
    ],

    'status_dialog' => [
        'suspend_title' => 'Suspend this offer',
        'suspend_description' => 'The Supplier cannot be chosen for new allocations until it is reactivated.',
        'reactivate_title' => 'Reactivate this offer',
        'reactivate_description' => 'The Supplier can be chosen for allocations again.',
    ],

    'picker' => [
        'title' => 'Choose a supplier',
        'description' => 'Approved Suppliers. Search by name, reference, email or mobile.',
        'search' => 'Search suppliers',
        'none' => 'No Supplier matches.',
        'failed' => 'Suppliers could not be loaded.',
        'select' => 'Select',
        'selected' => 'Selected',
        'done' => 'Done',
        'load_more' => 'Load more',
        'count' => '{0} None selected|{1} 1 selected|[2,*] :count selected',
    ],

    'supplier_section' => [
        'title' => 'Products',
        'description' => 'The Products this Supplier holds, from approved listings and from assignment.',
        'none' => 'This Supplier holds no Product yet.',
    ],
];
