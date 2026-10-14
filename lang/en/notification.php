<?php

return [
    /*
     * One entry per event written to the database channel (D20).
     *
     * The stored notification keeps only an `event` plus that event's own
     * fields, so the wording lives here rather than in the row. That is what
     * lets a notification sent in English read in Bangla when the recipient
     * switches language — and what lets us reword an alert without rewriting
     * history.
     *
     * A `description` is the generic fallback. Where an administrator wrote
     * instructions or feedback of their own, those are shown instead: their
     * words say what to do, this line only says what happened.
     */
    'events' => [
        'kyc.deadline_approaching' => [
            'title' => 'Your verification is due soon',
            'description' => 'Finish your verification before the deadline to keep your account active.',
        ],

        'kyc.deadline_missed' => [
            'title' => 'Your verification is overdue',
            'description' => 'Submit your verification documents to restore full access.',
        ],

        'kyc.deadline_restriction_lifted' => [
            'title' => 'Your account restriction has been lifted',
            'description' => 'Your verification is complete and the restriction has been removed.',
        ],

        'kyc.update_requested' => [
            'title' => 'We need updated verification documents',
            'description' => 'An administrator has asked for changes to your verification.',
        ],

        'account.kyc_resubmission_requested' => [
            'title' => 'Your verification needs a correction',
            'description' => 'Review the feedback and submit your documents again.',
        ],

        'account.activated' => [
            'title' => 'Your account is now active',
            'description' => 'You now have full access to your Banij account.',
        ],

        'account.suspended' => [
            'title' => 'Your account has been suspended',
            'description' => 'Contact support to find out what is needed to restore access.',
        ],

        'identity.new_device_sign_in' => [
            'title' => 'A new device signed in',
            'description' => 'If this was not you, change your password and sign out the other devices.',
        ],

        'identity.locked' => [
            'title' => 'Your sign-in has been locked',
            'description' => 'Contact support to have access restored.',
        ],

        'identity.unlocked' => [
            'title' => 'Your sign-in has been restored',
            'description' => 'You can sign in again.',
        ],

        'identity.password_changed' => [
            'title' => 'Your password was changed',
            'description' => 'If you did not change it, contact support immediately.',
        ],

        'inventory.stock_low' => [
            'title' => 'Stock is running low',
            'description' => 'A BPC has fallen to its low-stock threshold in a warehouse.',
        ],

        'inventory.stock_out' => [
            'title' => 'Stock has run out',
            'description' => 'A BPC has no stock available in a warehouse.',
        ],

        'orders.website_order_paid' => [
            'title' => 'A website order has been paid',
            'description' => 'A customer paid for an order on your website. Open the order to see where it stands.',
        ],

        'referral.commission_paid' => [
            'title' => 'A Partner Network commission was paid',
            'description' => 'It is in your wallet. Your Partner Network page shows each earning and its level.',
        ],

        'website.status_changed' => [
            'title' => 'Your website has changed state',
            'description' => 'Open the website to see where it stands and what it is waiting for.',
        ],

        'website.renewal_due' => [
            'title' => 'A website renewal is due',
            'description' => 'A domain or hosting term is running out. Renew it to keep the shop reachable.',
        ],

        /*
         * The Supplier account domain (D25). A wholly separate set of events
         * from the Partner ones above — a Supplier never receives the
         * `account.*` notifications, and vice versa.
         */
        'supplier.kyc_submitted' => [
            'title' => 'Your verification documents were received',
            'description' => 'We will review your Supplier application and let you know the outcome.',
        ],

        'supplier.kyc_correction_requested' => [
            'title' => 'Your verification needs a correction',
            'description' => 'Review the feedback and submit your documents again.',
        ],

        'supplier.approved' => [
            'title' => 'Your Supplier application has been approved',
            'description' => 'You can now submit product listing requests.',
        ],

        'supplier.rejected' => [
            'title' => 'Your Supplier application was not approved',
            'description' => 'Contact support to find out what is needed to reapply.',
        ],

        'supplier.suspended' => [
            'title' => 'Your Supplier account has been suspended',
            'description' => 'Contact support to find out what is needed to restore access.',
        ],

        'supplier.reactivated' => [
            'title' => 'Your Supplier account has been reactivated',
            'description' => 'You can resume submitting and managing listings.',
        ],

        'supplier.listing_submitted' => [
            'title' => 'Your product listing request was received',
            'description' => 'We will review it and let you know the outcome.',
        ],

        'supplier.listing_lot_submitted' => [
            'title' => 'Your product listing batch was received',
            'description' => 'We will review every product in the batch and let you know the outcome.',
        ],

        'supplier.fulfilment_commitment_expired' => [
            'title' => 'A fulfilment commitment was cancelled',
            'description' => 'You did not confirm it before the deadline, so it has been cancelled and returned to staff for reassignment.',
        ],

        'supplier.listing_correction_requested' => [
            'title' => 'Your product listing needs a correction',
            'description' => 'Review the feedback and resubmit your listing.',
        ],

        'supplier.listing_approved' => [
            'title' => 'Your product listing has been approved',
            'description' => 'Open the listing to see which items were connected to the catalogue.',
        ],

        'supplier.listing_rejected' => [
            'title' => 'Your product listing was not approved',
            'description' => 'Open the listing to see the reason.',
        ],

        'supplier.offer_suspended' => [
            'title' => 'One of your offers has been suspended',
            'description' => 'It is no longer available for purchase until reactivated.',
        ],

        /*
         * Work that has arrived in a staff queue (StaffQueue). The bell appends
         * the submission's own reference to each description.
         */
        'queue.kyc' => [
            'title' => 'New KYC application to review',
            'description' => 'A business has submitted its KYC documents.',
        ],
        'queue.supplier_kyc' => [
            'title' => 'New Supplier application to review',
            'description' => 'A Supplier has submitted its application.',
        ],
        'queue.supplier_listing' => [
            'title' => 'New Supplier listing to review',
            'description' => 'A Supplier has submitted a product listing.',
        ],
        'queue.supplier_listing_lot' => [
            'title' => 'New Supplier listing lot to review',
            'description' => 'A Supplier has submitted a batch of listings.',
        ],
        'queue.supplier_stock_update' => [
            'title' => 'New availability update to review',
            'description' => 'A Supplier has asked to change the stock it offers.',
        ],
        'queue.account_withdrawal' => [
            'title' => 'New withdrawal request',
            'description' => 'A partner has requested a withdrawal.',
        ],
        'queue.supplier_withdrawal' => [
            'title' => 'New Supplier withdrawal request',
            'description' => 'A Supplier has requested a withdrawal.',
        ],
        'queue.order_return' => [
            'title' => 'New return request',
            'description' => 'A return is waiting for a decision.',
        ],
        'queue.refund_request' => [
            'title' => 'New refund request',
            'description' => 'A refund is waiting for a decision.',
        ],
        'queue.website_request' => [
            'title' => 'New website request',
            'description' => 'A partner has asked for a website to be set up.',
        ],
        'queue.account_deletion' => [
            'title' => 'New account deletion request',
            'description' => 'Someone has asked to have their account erased.',
        ],
        'queue.supplier_deletion' => [
            'title' => 'New Supplier deletion request',
            'description' => 'A Supplier has asked to have its account erased.',
        ],
        'decision.deletion.rejected' => [
            'title' => 'Your deletion request was not approved',
            'description' => 'Open your profile to read why.',
        ],

        /*
         * Staff answered something the recipient asked for. Only the outcome is
         * shown; a reviewer's own note stays in the audit trail.
         */
        'decision.refund.approved' => [
            'title' => 'Your refund request was approved',
            'description' => 'Open your receipt to follow the refund.',
        ],
        'decision.refund.rejected' => [
            'title' => 'Your refund request was not approved',
            'description' => 'Open your receipt for the details.',
        ],
        'decision.return.approved' => [
            'title' => 'A return was approved',
            'description' => 'Open the order to see what happens next.',
        ],
        'decision.return.rejected' => [
            'title' => 'A return was not approved',
            'description' => 'Open the order to see the outcome.',
        ],
        'decision.stock_update.approved' => [
            'title' => 'Your availability update was approved',
            'description' => 'The new quantity is now live.',
        ],
        'decision.stock_update.rejected' => [
            'title' => 'Your availability update was not approved',
            'description' => 'The previous quantity stays in place.',
        ],
    ],

    'reference' => 'Reference :reference',
];
