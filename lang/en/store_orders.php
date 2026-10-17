<?php

return [
    'cancelled' => 'Cancelled',
    'by' => ['staff' => 'Feriwala staff', 'system' => 'System'],
    'courier' => ['courier_booked' => 'Courier Booked', 'picked_up' => 'Picked Up', 'in_transit' => 'In Transit', 'out_for_delivery' => 'Out for Delivery'],
    'nav' => 'Store Orders',
    'steps' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'packing' => 'Packing',
        'packed' => 'Packed',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
    ],
    'actions' => [
        'confirm' => 'Confirm Order',
        'start_processing' => 'Start Processing',
        'start_packing' => 'Start Packing',
        'mark_packed' => 'Mark Packed',
        'dispatch' => 'Dispatch Order',
    ],
    'refused' => [
        'not_store_order' => 'This is not a Store Order.',
        'wrong_step' => '\':action\' is not the next step: the order is at \':current\'.',
        'reason_required' => 'A reason is required to cancel an order.',
        'cannot_cancel_now' => 'This order cannot be cancelled now. Once dispatched it follows the return process.',
        'not_yet_dispatched' => 'Courier and delivery updates start only after the order is dispatched.',
        'stock_not_held' => 'The stock held for :sku is no longer reserved, so this order cannot be dispatched.',
        'use_actions' => 'A Store Order moves only through its workflow actions.',
    ],
    'notes' => [
        'confirm' => 'Your order has been confirmed.',
        'start_processing' => 'Your order is being processed.',
        'start_packing' => 'Your order is being packed.',
        'mark_packed' => 'Your order is packed and ready for the courier.',
        'dispatch' => 'Your order has been dispatched.',
        'cancelled' => 'Your order was cancelled.',
        'cancelled_refund' => 'Your order was cancelled. Your payment will be refunded.',
    ],
];
