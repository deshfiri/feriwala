<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Bcrypt, stated rather than inherited (§6). The framework default is the
    | same today, and a default is exactly the kind of thing that changes under
    | you between minor versions — a password store is not somewhere to discover
    | that after the fact.
    |
    | Argon2id is the stronger choice on paper and is deliberately not taken: it
    | needs libsodium tuned for the deployment's memory, and an untuned Argon is
    | weaker than a well-configured bcrypt as well as being a way to exhaust a
    | web node's RAM. Revisit with a memory budget, not on principle.
    |
    | Supported: "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | Cost 12 — roughly a quarter-second per hash on current hardware, which is
    | slow enough to be worth an attacker's time and fast enough that a login
    | does not feel broken. It is also the framework's current default, so this
    | pins today's behaviour rather than changing it.
    |
    | Raising it later costs nothing at rest: `rehash_on_login` re-hashes each
    | password the next time its owner signs in, so the store migrates itself
    | without anybody being asked to reset anything.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Present so the values are visible if the driver is ever switched, rather
    | than being inherited silently at the moment somebody changes one line.
    |
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | On. A password hashed under an older cost is re-hashed at the next sign-in
    | (§6), which is how the store keeps up with the configuration without a bulk
    | migration — and a bulk migration is impossible anyway, since the plaintext
    | needed to re-hash only exists during a login.
    |
    */

    'rehash_on_login' => true,

];
