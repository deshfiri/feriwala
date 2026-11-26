<?php

return [
    'title' => 'Storefront profit',
    'admin_title' => 'Storefront profits',
    'admin_description' => 'Every Partner\'s profit on Storefront orders: held for 24 hours after delivery, then credited to their withdrawable balance.',

    'fields' => [
        'order' => 'Order',
        'partner' => 'Partner',
        'status' => 'Status',
        'selling' => 'Net selling amount',
        'cost' => 'Product price / cost',
        'profit' => 'Profit',
        'released' => 'Credited',
        'reversed' => 'Taken back',
        'delivered_at' => 'Delivered',
        'collected_at' => 'Collected',
        'release_at' => 'Releases',
        'channel' => 'Collected through',
        'basis' => 'Cost basis',
        'quantity' => 'Qty',
        'returned' => 'Returned',
        'reason' => 'Why',
        'failure' => 'Failure',
        'attempts' => 'Attempts',
    ],

    'channels' => [
        'cod' => 'Cash on delivery',
        'platform_gateway' => 'Banij gateway',
        'partner_gateway_external' => 'Partner\'s own gateway',
    ],

    'basis' => [
        'platform_price' => 'Platform price (central stock)',
        'fifo_lots' => 'Purchase lots, first in first out',
        'historical_single_cost' => 'Only purchase price before the sale',
        'finance_correction' => 'Set by finance',
    ],

    'reasons' => [
        'awaiting_delivery' => 'Waiting for delivery.',
        'awaiting_collection' => 'Waiting for the money to be collected.',
        'order_cancelled' => 'The order was cancelled.',
        'partner_gateway' => 'Paid on the Partner\'s own gateway: shown for reporting, never credited.',
        'delivery_reversed' => 'The delivery was reversed.',
        'return_open' => 'A return is open.',
        'damage_open' => 'A damage case is open.',
        'refund_open' => 'A refund is in progress.',
        'collection_mismatch' => 'The amount collected does not match the order total.',
        'cost_unproven' => 'A product cost cannot be proven.',
        'negative_profit' => 'The profit is below zero.',
        'zero_profit' => 'The profit is exactly zero.',
        'already_credited' => 'Already credited by the earlier per-line settlement.',
        'technical_failure' => 'Crediting failed for a technical reason.',
        'review_required_after_release' => 'Credited, but a cost can no longer be proven.',
        'negative_profit_after_release' => 'Credited, then reduced below zero by a return.',
        'possible_duplicate' => 'An earlier credit may already represent this profit.',
        'delivered_time_unproven' => 'The original delivery time cannot be proven.',
    ],

    'unproven' => [
        'no_platform_price' => 'No platform price was frozen on this line.',
        'lot_cost_unknown' => 'Some units came from a lot whose cost is unknown.',
        'no_purchase_record' => 'No purchase of this product before the sale.',
        'ambiguous_purchase_cost' => 'Bought at more than one price before the sale.',
        'cost_unproven' => 'The cost cannot be proven.',
    ],

    'partner' => [
        'pending' => 'Pending profit',
        'pending_help' => 'Credited to your withdrawable balance 24 hours after delivery, once the money is collected.',
        'releases_at' => 'Releases :time',
        'credited' => 'Credited :time',
        'none' => 'No Storefront profit is pending.',
        'reported_only' => 'Paid on your own gateway: you already received this money.',
    ],

    'actions' => [
        'confirm_cod' => 'Confirm COD collected',
        'retry' => 'Retry credit',
        'correct' => 'Resolve cost',
        'view' => 'View',
    ],

    'confirm' => [
        'cod_title' => 'Confirm cash collected?',
        'cod_body' => 'Records the cash collected for the whole order. It cannot be changed afterwards.',
        'retry_title' => 'Retry this credit?',
        'retry_body' => 'Tries to credit the profit again. It is credited at most once.',
        'correct_title' => 'Set this line\'s cost?',
        'correct_body' => 'Records the purchase lots and quantities you chose. It is kept as decided and cannot be edited.',
    ],

    'correction' => [
        'title' => 'Resolve an unproven cost',
        'lot' => 'Purchase lot',
        'quantity' => 'Units',
        'reason' => 'Reason',
        'reference' => 'Supporting reference',
        'preview' => 'Preview',
        'preview_cost' => 'Cost of these units',
        'preview_profit' => 'Profit after this',
        'units_must_match' => 'The units chosen must add up to :quantity.',
        'lot_not_this_product' => 'That lot is not this Partner\'s stock of this product.',
        'lot_cost_unknown' => 'That lot\'s cost is unknown.',
        'lot_too_small' => 'That lot only had :quantity units.',
        'already_released' => 'This profit was already credited.',
    ],

    'flash' => [
        'cod_confirmed' => 'Cash collection recorded.',
        'retried' => 'Credit retried.',
        'corrected' => 'Cost recorded.',
        'hold_cleared' => 'Review hold cleared. The profit follows its facts again.',
    ],

    'summary' => [
        'pending' => 'Pending',
        'due' => 'Due now',
        'released' => 'Released',
        'reversed' => 'Reversed',
        'blocked' => 'Blocked',
        'unresolved' => 'Unresolved',
    ],

    'hold' => [
        'title' => 'Held for review',
        'clear' => 'Clear the hold',
        'reason' => 'What you checked',
        'confirm_title' => 'Clear this review hold?',
        'confirm_body' => 'The profit is credited when due, once. Only clear it after checking the earlier credit or the delivery time.',
        'all_reasons' => 'Every reason',
    ],

    'refused' => [
        'not_storefront' => 'This is not a Storefront order.',
        'not_cod' => 'This order was paid online; its collection comes from the gateway.',
        'amount_invalid' => 'Enter the amount collected, in the order\'s currency.',
        'already_collected' => 'A different amount was already recorded for this order.',
        'not_failed' => 'Only a credit that failed for a technical reason can be retried.',
    ],

    'ledger' => [
        'credit' => 'Storefront profit for order :order',
        'reversal' => 'Storefront profit taken back — order :order',
    ],

    'empty' => 'No Storefront profits match.',
];
