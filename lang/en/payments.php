<?php

return [
    'title' => 'Payments',
    'description' => 'Every payment and every exchange with a gateway behind it.',
    'nav' => 'Payments',
    'caption' => 'Payments, newest first',
    'search_placeholder' => 'Reference, gateway transaction, or account',

    'needs_attention' => ':count payment needs reconciliation|:count payments need reconciliation',
    'needs_attention_help' => 'Money arrived for a purchase that had already closed. Nothing was activated and nothing was refunded — these are waiting on a person.',
    'only_reconciliation' => 'Needs reconciliation',
    'all_statuses' => 'Any status',
    'all_gateways' => 'Any gateway',

    'columns' => [
        'reference' => 'Payment',
        'account' => 'Account',
        'gateway' => 'Gateway',
        'gateway_reference' => 'Gateway transaction',
        'amount' => 'Amount',
        'status' => 'Status',
        'created' => 'Started',
        'completed' => 'Completed',
    ],

    'empty_title' => 'No payments yet',
    'empty_description' => 'They appear here as soon as somebody starts a checkout.',

    'open' => 'Open',
    'back' => 'Back to payments',

    'detail' => [
        'summary' => 'Payment',
        'purpose' => 'For',
        'invoice' => 'Invoice',
        'settled' => 'Settled amount',
        'initiated' => 'Sent to gateway',
        'expires' => 'Deadline',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'failure_reason' => 'Failure reason',
        'none' => '—',
    ],

    'reconciliation' => [
        'title' => 'Needs reconciliation',
        'flagged' => 'Flagged :date',
        'help' => 'The gateway confirmed this payment after the checkout had closed. The account was not activated and the invoice was not changed. Refund or reconcile it with the provider, then record what was done.',
    ],

    'manual' => [
        'title' => 'Mark as paid',
        'help' => 'Use this when the customer paid at the gateway but the payment still reads failed, cancelled or stuck here. The gateway is asked to confirm the transaction first. Everything the payment unlocks then happens exactly as if the gateway had confirmed it, and your name and reason are recorded.',
        'confirm_first' => 'Marking a payment as paid needs your password again. Confirm it, and you will come back here.',
        'confirm' => 'Confirm your password',
        'reference' => 'Gateway transaction ID',
        'reference_hint' => 'The provider\'s own transaction or validation ID, from the gateway dashboard or the customer\'s receipt.',
        'reason' => 'Why you are marking it paid',
        'override' => 'The gateway could not confirm it, but I have seen the money arrive another way. Mark it paid anyway.',
        'confirm_box' => 'I confirm this money has really been received.',
        'submit' => 'Mark as paid',
        'done' => 'Payment :reference has been marked as paid.',
        'already' => 'Payment :reference was already paid. Nothing was changed.',
    ],

    'log' => [
        'title' => 'Gateway trail',
        'description' => 'Redacted before it was stored, and again before it was sent here: no credentials, no signatures, no unmasked personal data (§42).',
        'empty' => 'Nothing has been exchanged with a gateway for this payment.',
        'inbound' => 'Received',
        'outbound' => 'Sent',
        'at' => 'When',
        'event' => 'Event',
        'outcome' => 'Outcome',
        'from' => 'From :ip',
        'show_payload' => 'Show payload',
        'hide_payload' => 'Hide payload',
        'withheld' => ':count field was withheld and is not shown here (§42).|:count fields were withheld and are not shown here (§42).',
    ],
];
