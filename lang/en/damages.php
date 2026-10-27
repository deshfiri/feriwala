<?php

/*
 * Damaged items (D35).
 *
 * The notes are what a Partner reads on a damage's timeline, so they say what
 * happened in plain words and never how Banij keeps its stock, who inside Banij
 * decided it, or anything about the customer.
 */
return [
    'notes' => [
        'reported' => 'Damage reported.',
        'awaiting_receipt' => 'Waiting for the item to arrive.',
        'received' => 'The item arrived.',
        'under_inspection' => 'The item is being inspected.',
        'inspection_recorded' => 'Inspection finished. We are deciding who bears the loss.',
        'no_damage_found' => 'Inspection found no damage. This report is closed.',
        'approved' => [
            'partner' => 'The loss was approved and is yours to bear.',
            'banij' => 'The loss was approved and is Banij\'s to bear. Nothing is charged to you.',
        ],
        'rejected' => 'This damage report was closed without a loss.',
        'finalized' => 'The loss was posted.',
        'delivery_requested' => 'Delivery of the damaged item was requested.',
        'dispatched' => 'The damaged item is on its way to you.',
        'received_by_partner' => 'You received the damaged item.',
    ],

    'storage_states' => [
        'not_held' => 'Not held by Banij',
        'held_for_inspection' => 'Held for inspection',
        'held_by_banij' => 'Held by Banij, not for sale',
        'awaiting_dispatch' => 'Waiting to be sent to you',
        'in_transit' => 'On its way to you',
        'with_partner' => 'With you',
    ],

    'delivery_states' => [
        'not_available' => 'Not available',
        'available' => 'You can ask for delivery',
        'requested' => 'Requested',
        'dispatched' => 'On its way',
        'delivered' => 'Delivered',
    ],
];
