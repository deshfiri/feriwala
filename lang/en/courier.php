<?php

return [

    'admin' => [
        'title' => 'Shipments',
        'description' => 'Every shipment raised for an order, and the courier carrying it.',
        'caption' => 'Shipments',
        'back' => 'Back to shipments',
        'for_order' => 'For order :reference',
        'details_title' => 'Shipment details',
        'packages_title' => 'Packages',
        'no_packages' => 'No packages recorded.',
        'tracking_title' => 'Tracking events',
        'no_tracking_events' => 'No tracking events recorded yet.',
        'history_title' => 'Status history',
        'no_history' => 'No status changes recorded yet.',
        'reason' => 'Reason',
        'reason_description' => 'Recorded on the shipment and in the audit log.',
        'cancelled_reason' => 'Cancelled: :reason',
        'shipment_created' => 'Shipment created.',
        'status_updated' => 'Shipment status updated.',

        'columns' => [
            'reference' => 'Shipment',
            'order' => 'Order',
            'provider' => 'Courier',
            'status' => 'Status',
            'delivery_charge' => 'Delivery charge',
            'created_at' => 'Created',
        ],

        'fields' => [
            'provider' => 'Courier',
            'tracking_number' => 'Tracking number',
            'delivery_charge' => 'Delivery charge',
            'cod_amount' => 'Cash on delivery',
            'pickup_requested_at' => 'Pickup requested',
            'weight' => 'Weight (kg)',
            'dimensions' => 'Dimensions (cm)',
            'package_size' => 'Package size',
        ],

        'filter_status' => 'Filter by status',
        'all_statuses' => 'All statuses',
        'no_matches' => 'No shipments match',
        'no_matches_help' => 'Try a different status filter.',
        'empty' => 'No shipments yet',
        'empty_help' => 'Shipments created from an order\'s ready-to-dispatch lines will appear here.',

        'create_action' => 'Create shipment',
        'create_title' => 'Create a shipment for :reference',
        'create_description' => 'Only the manual courier can be used today — enter the tracking number and delivery charge yourself.',
        'provider' => 'Courier',
        'provider_help' => 'Steadfast and Pathao require provider credentials this application does not have yet.',
        'tracking_number' => 'Tracking number',
        'delivery_charge' => 'Delivery charge',
        'cod_amount' => 'Cash on delivery amount',
        'package_weight' => 'Weight (kg)',
        'package_length' => 'Length (cm)',
        'package_width' => 'Width (cm)',
        'package_height' => 'Height (cm)',

        'actions' => [
            'assigned' => 'Reassign courier',
            'pickup_requested' => 'Request pickup',
            'picked_up' => 'Mark picked up',
            'in_transit' => 'Mark in transit',
            'delivered' => 'Mark delivered',
            'failed_delivery' => 'Record failed delivery',
            'returned_to_origin' => 'Mark returned to origin',
            'cancelled' => 'Cancel shipment',
        ],
    ],

];
