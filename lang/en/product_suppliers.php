<?php

return [
    'assigned' => '{1} Supplier assigned.|[2,*] :count assignments made.',
    'removed' => 'Supplier removed from this Product.',

    'errors' => [
        'product_not_found' => 'One of the chosen Products could not be found.',
        'supplier_not_found' => 'One of the chosen Suppliers could not be found.',
        'supplier_not_approved' => 'Only approved Suppliers can be assigned to a Product.',
        'already_assigned' => 'Every chosen Supplier already has this Product.',
    ],

    'section' => [
        'title' => 'Suppliers',
        'description' => 'The Suppliers that actually have this Product. This records who holds the item; prices, stock and approval stay in the Supplier listings.',
        'assign' => 'Assign suppliers',
        'none' => 'No Supplier is assigned to this Product yet.',
        'assigned_by' => 'Assigned by :name on :date',
        'remove' => 'Remove',
        'view' => 'View supplier',
        'group_title' => 'Suppliers of the same Product',
        'group_help' => 'These Suppliers are assigned to other Products in this Same Product group. Shown for reference; assign them here to make them this Product\'s own.',
        'group_via' => 'Assigned to: :products',
    ],

    'picker' => [
        'title' => 'Choose suppliers',
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

    'confirm_assign' => [
        'title' => 'Assign suppliers',
        'description' => 'These Suppliers will be recorded as having this Product.',
        'reference_label' => 'Supplier\'s own name or code for it (optional)',
        'reason_label' => 'Reason (optional)',
        'confirm' => 'Assign',
    ],

    'confirm_remove' => [
        'title' => 'Remove supplier',
        'description' => 'The Supplier will no longer be listed as having this Product. The record is kept as history.',
        'confirm' => 'Remove',
    ],

    'supplier_section' => [
        'title' => 'Assigned products',
        'description' => 'The Products this Supplier actually has.',
        'assign' => 'Assign products',
        'none' => 'No Product is assigned to this Supplier yet.',
        'assign_title' => 'Choose products',
        'assign_description' => 'Pick the Products this Supplier has.',
    ],
];
