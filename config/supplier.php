<?php

return [

    /*
     * How long a Supplier has to confirm a non-ready-stock fulfilment
     * commitment before it is swept back to staff review
     * (ExpireOverdueFulfilmentCommitments, Advanced Order Management batch).
     */
    'fulfilment_confirmation_deadline_hours' => (int) env('SUPPLIER_FULFILMENT_CONFIRMATION_DEADLINE_HOURS', 48),

];
