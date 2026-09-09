<?php

/*
 * Bangla authentication messages (§6).
 *
 * Laravel's own `en` file supplies these in English; this is the same three
 * lines in Bangla, and nothing more. They matter more than most: they are the
 * only thing a person sees when they cannot get in, and someone locked out by a
 * message they cannot read has no way to tell "wrong password" from "wait a
 * moment" — so they keep trying, which is precisely what the wait exists to stop.
 *
 * `failed` says the credentials do not match, and deliberately not which half of
 * them. Naming the email would turn the sign-in form into a way of asking
 * whether an address has an account here.
 */

return [
    'failed' => 'এই তথ্য আমাদের রেকর্ডের সঙ্গে মেলেনি।',

    'password' => 'পাসওয়ার্ডটি সঠিক নয়।',

    'throttle' => 'অনেকবার চেষ্টা করা হয়েছে। :seconds সেকেন্ড পর আবার চেষ্টা করুন।',
];
