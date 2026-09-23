<?php

use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * The Supplier account domain's own guard (D25). A Supplier is never
         * authenticated through `web` — this is a distinct provider pointed
         * at a distinct model and table, so a Supplier session and a
         * Client/Partner session are never the same authenticated identity,
         * whatever else a shared browser session happens to also hold.
         */
        'supplier' => [
            'driver' => 'session',
            'provider' => 'suppliers',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],

        'suppliers' => [
            'driver' => 'eloquent',
            'model' => Supplier::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),

            /*
             * Minutes a reset link stays usable (§6).
             *
             * A reset link is a password sitting in a mailbox, and the mailbox
             * is the thing most likely to be compromised later — so the window
             * is short enough that a link found in six months' worth of archived
             * mail is worth nothing. Configurable because "short enough" is an
             * operational judgement, and named here rather than left to the
             * framework default so it is a decision somebody made.
             *
             * The token is single-use regardless: Laravel deletes it the moment
             * a reset succeeds, so this bounds the unused ones.
             */
            'expire' => (int) env('AUTH_PASSWORD_RESET_EXPIRE', 60),

            /*
             * Seconds before another link may be requested for the same
             * address. Stops the reset form being used to flood somebody's
             * inbox, and stops a stream of links each of which is a live
             * credential.
             */
            'throttle' => (int) env('AUTH_PASSWORD_RESET_THROTTLE', 60),
        ],

        /*
         * A Supplier resets their own password against their own table
         * (`supplier_password_reset_tokens`) — never `password_reset_tokens`,
         * which belongs to Client/Partner and staff identities (D25).
         */
        'suppliers' => [
            'provider' => 'suppliers',
            'table' => 'supplier_password_reset_tokens',
            'expire' => (int) env('AUTH_PASSWORD_RESET_EXPIRE', 60),
            'throttle' => (int) env('AUTH_PASSWORD_RESET_THROTTLE', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
