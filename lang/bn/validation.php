<?php

/*
 * Bangla validation messages.
 *
 * Deliberately partial. Laravel falls back to the `en` locale key by key, so
 * this file carries only what the application actually says differently — the
 * password policy (§6) above all, because a rule a reader cannot understand is
 * a rule they cannot satisfy, and they will simply keep guessing.
 */

return [
    'min' => [
        'string' => ':attribute কমপক্ষে :min অক্ষরের হতে হবে।',
    ],

    'confirmed' => ':attribute দুবার একই রকম হয়নি।',

    'current_password' => 'পাসওয়ার্ডটি সঠিক নয়।',

    'password' => [
        'letters' => ':attribute-এ অন্তত একটি অক্ষর থাকতে হবে।',
        'mixed' => ':attribute-এ অন্তত একটি বড় হাতের ও একটি ছোট হাতের অক্ষর থাকতে হবে।',
        'numbers' => ':attribute-এ অন্তত একটি সংখ্যা থাকতে হবে।',
        'symbols' => ':attribute-এ অন্তত একটি বিশেষ চিহ্ন থাকতে হবে।',
        'uncompromised' => 'এই :attribute একটি ফাঁস হওয়া তালিকায় পাওয়া গেছে। অন্য একটি বেছে নিন।',
    ],

    'attributes' => [
        'password' => 'পাসওয়ার্ড',
        'current_password' => 'বর্তমান পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিতকরণ',
    ],
];
