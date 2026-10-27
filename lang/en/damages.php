<?php

/*
 * Damaged items (D35).
 *
 * The notes are what a Partner reads on a damage's timeline, so they say what
 * happened in plain words and never how Banij keeps its stock, who inside Banij
 * decided it, or anything about the customer.
 */
return [
    // `My Products > My Damages` (D35).
    'title' => 'My Damages',
    'description' => 'Damaged items you bear the loss for. A damage that was Banij\'s fault is Banij\'s loss and is not listed here.',
    'empty' => 'No damaged items',
    'empty_help' => 'Items you bear the loss for appear here once the loss is decided.',
    'back' => 'All damaged items',
    'open' => 'Open',
    'summary' => 'Damaged item',
    'order' => 'Order',
    'quantity' => 'Units',
    'condition' => 'Condition',
    'cause' => 'Cause',
    'loss' => 'Approved loss',
    'responsibility' => 'Responsibility',
    'storage' => 'Where it is',
    'delivery' => 'Delivery',
    'disposition' => 'What happened to the units',
    'never_sellable' => 'A damaged item is never put back on sale, whether it is held, on its way or with you.',
    'remains_with_customer' => 'Product remains with customer. There is no parcel for us to receive and nothing to deliver to you.',
    'deduction' => 'Deduction',
    'delivery_title' => 'Delivery to you',
    'delivery_charge' => 'Delivery charge',
    'requested_at' => 'Requested',
    'dispatched_at' => 'Dispatched',
    'received_at' => 'Received',
    'tracking' => 'Tracking',
    'request_delivery' => 'Request delivery',
    'confirm_received' => 'I received it',
    'delivery_help' => 'We will deliver the damaged item to one of your saved addresses. You pay the delivery charge from your wallet.',
    'units_to_send' => 'Units to send',
    'charge_is_the_servers' => 'This charge is worked out by Banij. It cannot be changed here.',
    'wallet_too_low' => 'Your wallet balance does not cover this charge. Top up your wallet first.',
    'deliver_to' => 'Deliver to',
    'no_address' => 'You have no active saved address.',
    'add_address' => 'Add an address',
    'confirm_payment' => 'Pay the delivery charge from my wallet.',
    'pay_and_request' => 'Pay and request delivery',

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
