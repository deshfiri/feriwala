<?php

return [
    'title' => 'Wallet',
    'description' => 'What your account holds, and every movement behind it.',

    /*
     * §33.7 asks a wallet screen to distinguish these from one another. They are
     * not synonyms, and the help text says what each one means rather than
     * leaving the reader to work out why two figures differ.
     */
    'balances' => [
        'total' => 'Total balance',
        'total_help' => 'Everything in the wallet, including money already spoken for.',
        'usable' => 'Available to spend',
        'usable_help' => 'The total, less anything reserved, held or still clearing.',
        'withdrawable' => 'Available to withdraw',
        'withdrawable_help' => 'What could leave the wallet, after the deposit you must keep in place.',
        'required_deposit' => 'Required deposit',
        'required_deposit_help' => 'Held against your trading, not spent.',
        'reserved' => 'Reserved',
        'reserved_help' => 'Set aside against charges that are coming.',
        'pending' => 'Pending',
        'pending_help' => 'Credited, not yet cleared.',
        'hold' => 'On hold',
        'hold_help' => 'Frozen while something is reviewed.',
        'cod_receivable' => 'COD receivable',
        'cod_receivable_help' => 'Collected by a courier, not yet settled to you.',
    ],

    'shortfall' => [
        'title' => 'Your wallet is below its required deposit',
        'description' => 'Add :amount to bring the deposit back up. Until then some services may be restricted.',
    ],

    'statement' => [
        'title' => 'Statement',
        'caption' => 'Wallet movements, newest first',
        'search_placeholder' => 'Reference or description',
        'export' => 'Export CSV',
        'open' => 'Open',
        'all_types' => 'Any type',
        'all_statuses' => 'Any status',
        'all_directions' => 'In and out',
        'from' => 'From',
        'to' => 'To',
        'empty_title' => 'Nothing has moved yet',
        'empty_description' => 'Top-ups, charges and earnings appear here as they happen. A zero balance is not a missing feature.',
    ],

    'columns' => [
        'date' => 'Date',
        'description' => 'Movement',
        'type' => 'Type',
        'debit' => 'Out',
        'credit' => 'In',
        'balance' => 'Balance',
        'status' => 'Status',
        'account' => 'Account',
        'total' => 'Total',
        'available' => 'Available',
    ],

    'detail' => [
        'title' => 'Movement',
        'back' => 'Back to the statement',
        'reference' => 'Reference',
        'type' => 'Type',
        'status' => 'Status',
        'amount' => 'Amount',
        'description' => 'Description',
        'reason' => 'Reason given',
        'source' => 'Recorded by',
        'payment' => 'Payment',
        'at' => 'When',
        'entries' => 'Ledger entries',
        'entries_help' => 'Written once and never changed. A correction is a new entry pointing at this one (§23.2).',
        'entry_reference' => 'Entry',
        'balance_before' => 'Balance before',
        'balance_after' => 'Balance after',
        'corrects' => 'Corrects',
        'no_entries' => 'Nothing has been posted for this movement yet — it holds money in place rather than moving it.',
        'internal_note' => 'Internal note',
        'internal_note_help' => 'Staff only. The account holder never sees this.',
    ],

    'admin' => [
        'title' => 'Account wallets',
        'description' => 'Every business account’s wallet, and the ledger behind each one.',
        'caption' => 'Wallets, largest balance first',
        'search_placeholder' => 'Account name',
        'empty_title' => 'No wallets yet',
        'empty_description' => 'A wallet opens when an account is activated.',
        'open' => 'Open',
        'back' => 'Back to wallets',
        'wallet_of' => 'Wallet — :account',
        'below_deposit' => 'Below required deposit, short by :amount',

        'adjust' => [
            'action' => 'Manual adjustment',
            'title' => 'Move money by hand',
            'description' => 'This posts a real entry against a real business’s wallet. It cannot be edited afterwards — only answered by another entry.',
            'amount' => 'Amount, in minor units',
            'amount_help' => 'Whole minor units. 2500 is ৳25.00.',
            'direction' => 'Direction',
            'credit' => 'Credit — add to the wallet',
            'debit' => 'Debit — take from the wallet',
            'reason' => 'Reason',
            'reason_help' => 'Recorded in the audit log with your name. Ten characters at least.',
            'internal_note' => 'Internal note (optional)',
            'internal_note_help' => 'Staff only. Never shown to the account holder.',
            'confirm' => 'I understand this posts a permanent ledger entry',
            'submit' => 'Post the adjustment',
        ],

        'reverse' => [
            'action' => 'Reverse',
            'title' => 'Reverse this movement',
            'description' => 'The original entry stays exactly as it is. A new entry of the opposite direction answers it, and the wallet returns to where it was.',
            'summary' => ':type of :amount, posted :at.',
            'reason' => 'Reason',
            'reason_help' => 'Why this is being reversed. Recorded in the audit log with your name.',
            'confirm' => 'I understand this posts a permanent reversing entry',
            'submit' => 'Post the reversal',
        ],

        'adjusted' => 'Adjustment :reference posted.',
        'reversed' => 'Reversal :reference posted.',
        'nothing_to_reverse' => 'Nothing has been posted for this movement, so there is nothing to reverse.',
    ],
];
