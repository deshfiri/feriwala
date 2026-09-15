<?php

return [
    'title' => 'Wholesale orders',
    'description' => 'Every wholesale order your business has placed, and where each one stands.',
    'empty' => 'No wholesale orders yet',
    'empty_help' => 'Orders you place from the wholesale cart appear here, so you can follow them to delivery.',
    'browse' => 'Wholesale catalogue',
    'view' => 'View order',
    'items_count' => 'Products: :count',
    'previous' => 'Previous',
    'next' => 'Next',
    'page' => 'Page :current of :last',

    'order_title' => 'Order :reference',
    'placed_on' => 'Placed on :date',
    'placed_by' => 'by :name',
    'back' => 'All wholesale orders',

    'statuses' => [
        'draft' => 'Draft',
        'new' => 'New',
        'pending_confirmation' => 'Pending confirmation',
        'customer_verification_pending' => 'Customer verification pending',
        'confirmed' => 'Confirmed',
        'payment_pending' => 'Payment pending',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'stock_reserved' => 'Stock reserved',
        'ready_for_fulfillment' => 'Ready for fulfillment',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'ready_for_pickup' => 'Ready for pickup',
        'courier_assigned' => 'Courier assigned',
        'shipped' => 'Shipped',
        'in_transit' => 'In transit',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'delivery_failed' => 'Delivery failed',
        'on_hold' => 'On hold',
        'cancelled' => 'Cancelled',
        'return_requested' => 'Return requested',
        'return_approved' => 'Return approved',
        'returning' => 'Returning',
        'returned' => 'Returned',
        'refund_pending' => 'Refund pending',
        'partially_refunded' => 'Partially refunded',
        'refunded' => 'Refunded',
    ],

    'payment_states' => [
        'awaiting' => 'Awaiting payment',
        'confirming' => 'Confirming payment',
        'paid' => 'Paid',
        'failed' => 'Payment failed',
        'cancelled' => 'Payment cancelled',
        'reconciliation' => 'Payment under review',
        'refunded' => 'Refunded',
    ],

    'stock_states' => [
        'held' => 'Stock held',
        'committed' => 'Stock set aside',
        'released' => 'Stock released',
        'attention' => 'Stock under review',
    ],

    'states' => [
        'awaiting_title' => 'Waiting for your payment',
        'awaiting_held' => 'The stock for this order is held for you until :time.',
        'awaiting_lapsed' => 'The time to pay for this order has run out.',
        'confirming_title' => 'Confirming your payment',
        'confirming_body' => 'We are confirming your payment with :gateway. This page shows the result once it is confirmed.',
        'paid_title' => 'Payment received',
        'paid_body' => 'Paid on :date. The stock for this order is set aside for you.',
        'on_hold_title' => 'Your order is being reviewed',
        'on_hold_body' => 'Your payment arrived, but we could not secure all of the stock for this order. Our team is reviewing it and will contact you. You will not be charged again.',
        'reconciliation_title' => 'Your payment is being reviewed',
        'reconciliation_body' => 'Your payment arrived after this order had already been cancelled. Our team will contact you about a refund.',
        'cancelled_title' => 'This order was cancelled',
        'cancelled_body' => 'No stock is held for it, and nothing further will be charged.',
    ],

    'sections' => [
        'items' => 'Products',
        'summary' => 'Summary',
        'billing' => 'Billing address',
        'shipping' => 'Shipping address',
        'payment' => 'Payment',
        'timeline' => 'Order timeline',
        'note' => 'Your note',
        'invoice' => 'Invoice',
    ],

    'lines' => [
        'quantity_each' => ':quantity × :amount',
        'discount' => 'Discount :amount',
        'tax' => 'Tax :amount',
    ],

    'summary' => [
        'subtotal' => 'Subtotal',
        'discount' => 'Discount',
        'coupon' => 'Coupon :code',
        'delivery' => 'Delivery',
        'tax' => 'Tax',
        'tax_included' => 'Prices include :amount tax',
        'total' => 'Total',
    ],

    'payment' => [
        'reference' => 'Payment reference',
        'method' => 'Payment method',
        'status' => 'Payment status',
        'stock' => 'Stock',
    ],

    'invoice' => [
        'view' => 'View invoice :number',
        'none' => 'The invoice is issued once your payment is confirmed.',
    ],

    'timeline' => [
        'empty' => 'No updates yet.',
    ],
];
