<?php

/*
|--------------------------------------------------------------------------
| Where a reason is required
|--------------------------------------------------------------------------
|
| A reason is asked for only where it is a security matter. Everywhere else it
| is optional, and {@see \App\Http\Middleware\DefaultReasonUnlessSecurity}
| supplies "No reason given." when it is left out, so the audit trail and the
| code that stores a reason are unchanged.
|
| Both lists are route-name patterns (`Str::is`). A route is defaulted when it
| matches `default_for` and does not match `required_for`.
|
*/

return [
    // Staff screens, and the two partner forms with a free-text reason.
    'default_for' => [
        'admin.*',
        'subscription.cancel',
        'websites.integration.*',
    ],

    // Security: who can do what, sign-in access, account and Supplier
    // suspension, verification done by hand, and money moved or corrected by
    // hand. These keep demanding a reason.
    'required_for' => [
        'admin.roles.*',
        'admin.permissions.*',
        'admin.staff.*',
        'admin.identities.*',
        'admin.accounts.suspend',
        'admin.activations.suspend',
        'admin.accounts.reactivate',
        'admin.suppliers.suspension.*',
        'admin.suppliers.reactivation.*',
        'admin.accounts.verify-contact',
        'admin.suppliers.verify-contact',
        'admin.*.setup-link.*',
        'admin.payments.manual-settlement',
        'admin.wallets.adjustments.*',
        'admin.wallets.reversals.*',
    ],
];
