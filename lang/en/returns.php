<?php

/*
 * Returns and their refunds (§18.2, §26.3, contract §6.3, P6-12).
 *
 * The notes are what a customer and their shop read on a return's timeline, so
 * they say what happened in plain words and never how Feriwala keeps its stock.
 */
return [
    'notes' => [
        'requested' => 'Return requested. We will let you know once it has been reviewed.',
        'approved' => 'Return approved. Please send the items back.',
        'rejected' => 'Return not accepted.',
        'received' => 'The returned items arrived and were checked.',
        'refunded' => 'The refund for this return has been settled.',
        'cancelled' => 'The return was withdrawn.',
    ],
];
