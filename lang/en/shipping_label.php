<?php

return [

    // On the printed label.
    'deliver_to' => 'Deliver to',
    'paid' => 'PAID',
    'cod' => 'COD :amount',
    'parcel_of' => 'Parcel :number of :count',
    'order' => 'Order',
    'store_order' => 'Store ref',
    'consignment' => 'Consignment',
    'tracking' => 'Tracking',
    'instructions' => 'Note',
    'items' => '{1} :count item|[2,*] :count items',
    'packages' => '{1} :count package|[2,*] :count packages',
    'fulfilled_by' => 'Fulfilled by Banij',
    'return_to' => 'Return to',
    'corrected_version' => 'Corrected v:version',
    'powered_by' => 'Powered by Banij',
    'aria' => 'Shipping label for order :reference',
    'batch_missing' => '{1} :count shipment has no printable label and was left out: :references|[2,*] :count shipments have no printable label and were left out: :references',

    'status' => [
        'not_available' => 'Not available',
        'needs_information' => 'Needs information',
        'ready' => 'Ready',
        'corrected' => 'Corrected',
    ],

    'refused' => [
        'not_storefront_order' => 'Shipping labels are printed for Storefront orders only.',
        'storefront_missing' => 'This order is not linked to a Storefront. Review the order before printing a label.',
        'shipment_cancelled' => 'This shipment is cancelled.',
        'waiting_for_courier' => 'Label waiting for courier information.',
        'barcode_unencodable' => 'The courier reference cannot be printed as a barcode. Check it against the courier\'s panel.',
        'recipient_incomplete' => 'The order is missing the recipient\'s name, phone or address. Staff must review it before a label can be printed.',
        'warehouse_undetermined' => 'Fulfilment warehouse could not be determined',
        'multiple_warehouses' => 'This order requires fulfilment from multiple warehouses and cannot use a single parcel.',
        'warehouse_not_ready' => 'The fulfilling warehouse has no approved courier contact and complete return address yet.',
        'cod_unknown' => 'This is a cash-on-delivery order but the shipment has no COD amount.',
        'no_current_label' => 'This shipment has no label to correct yet.',
        'nothing_changed' => 'Nothing on the label would change, so no new version was made.',
        'issue_conflict' => 'The label is being issued elsewhere. Try again in a moment.',
    ],

    // On the screens that print it.
    'panel' => [
        'title' => 'Shipping label',
        'size' => 'Label size',
        'print' => 'Print label',
        'print_size' => 'Print :size',
        'download_pdf' => 'Download PDF',
        'pdf_hint' => 'Choose "Save as PDF" as the printer.',
        'issue' => 'Generate label',
        'retry' => 'Try again',
        'reissue' => 'Regenerate corrected label',
        'reissue_description' => 'A new version is printed from the corrected records. The current version is kept, marked as replaced.',
        'reason' => 'Reason',
        'version' => 'Version :version',
        'issued' => 'Issued :date',
        'replaced' => 'Replaced :date: :reason',
        'versions' => 'Versions',
        'partner_waiting' => 'The label will be ready once the courier confirms the parcel.',
        'print_selected' => 'Print labels',
        'none_selected' => 'Select shipments to print their labels.',
    ],

    'flash' => [
        'issued' => 'Shipping label ready.',
        'reissued' => 'Corrected label issued as version :version.',
    ],

];
