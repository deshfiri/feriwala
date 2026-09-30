<?php

/*
 * Bangla authentication messages and the sign-in, registration and recovery
 * screens (§6).
 *
 * The first three lines matter more than most: they are the only thing a person
 * sees when they cannot get in, and someone locked out by a message they cannot
 * read has no way to tell "wrong password" from "wait a moment" — so they keep
 * trying, which is precisely what the wait exists to stop.
 *
 * `failed` says the credentials do not match, and deliberately not which half of
 * them. Naming the email would turn the sign-in form into a way of asking
 * whether an address has an account here.
 */

return [
    'failed' => 'এই তথ্য আমাদের রেকর্ডের সঙ্গে মেলেনি।',

    'password' => 'পাসওয়ার্ডটি সঠিক নয়।',

    'throttle' => 'অনেকবার চেষ্টা করা হয়েছে। :seconds সেকেন্ড পর আবার চেষ্টা করুন।',

    'login' => [
        'title' => 'লগইন',
        'heading' => 'আবার স্বাগতম',
        'intro' => 'আপনার ওয়ার্কস্পেসে প্রবেশ করে ব্যবসা পরিচালনা চালিয়ে যান।',
        'email' => 'ইমেইল ঠিকানা',
        'email_placeholder' => 'email@example.com',
        'password' => 'পাসওয়ার্ড',
        'password_placeholder' => 'আপনার পাসওয়ার্ড লিখুন',
        'forgot' => 'পাসওয়ার্ড ভুলে গেছেন?',
        'remember' => 'আমাকে মনে রাখুন',
        'submit' => 'সাইন ইন',
        'submitting' => 'সাইন ইন হচ্ছে...',
        'new_here' => 'প্ল্যাটফর্মে নতুন?',
        'create_account' => 'অ্যাকাউন্ট খুলুন',
        'supplier_prompt' => 'সাপ্লায়ার হতে চান?',
        'supplier_hint' => 'আমাদের ক্রমবর্ধমান সাপ্লায়ার নেটওয়ার্কে যুক্ত হোন।',
        'supplier_cta' => 'সাপ্লায়ার হোন',
    ],

    'showcase' => [
        'eyebrow' => 'একটি সংযুক্ত ওয়ার্কস্পেস',
        'heading' => 'আপনার ব্যবসা চালান',
        'heading_muted' => 'আরও নিয়ন্ত্রণের সঙ্গে।',
        'description' => 'একটি সংযুক্ত ওয়ার্কস্পেস থেকে অর্ডার ট্র্যাক করুন, পারফরম্যান্স দেখুন এবং প্রতিদিনের কাজ পরিচালনা করুন।',
        'overview' => 'সারসংক্ষেপ',
        'dashboard' => 'ব্যবসার ড্যাশবোর্ড',
        'this_month' => 'এই মাসে',
        'orders' => 'অর্ডার',
        'orders_change' => 'এই মাসে +১২.৫%',
        'revenue' => 'আয়',
        'total_revenue' => 'মোট আয়',
        'customers' => 'গ্রাহক',
        'active_customers' => 'সক্রিয় গ্রাহক',
        'sales_overview' => 'বিক্রয়ের সারসংক্ষেপ',
        'performance' => 'সময়ের সঙ্গে পারফরম্যান্স',
        'trust' => 'আপনার ব্যবসা এগিয়ে রাখতে তৈরি।',
    ],

    'register' => [
        'title' => 'রেজিস্টার',
        'layout_title' => 'অ্যাকাউন্ট খুলুন',
        'layout_description' => 'অ্যাকাউন্ট খুলতে নিচে আপনার তথ্য দিন',
        'name' => 'নাম',
        'name_placeholder' => 'পূর্ণ নাম',
        'email' => 'ইমেইল ঠিকানা',
        'mobile' => 'মোবাইল নম্বর',
        'mobile_hint' => 'এই নম্বরে আমরা একটি যাচাইকরণ কোড পাঠাব।',
        'password' => 'পাসওয়ার্ড',
        'password_placeholder' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিত করুন',
        'password_confirmation_placeholder' => 'পাসওয়ার্ড নিশ্চিত করুন',
        'optional_legend' => 'ঐচ্ছিক — পরে যোগ করতে পারবেন',
        'date_of_birth' => 'জন্ম তারিখ',
        'gender' => 'লিঙ্গ',
        'gender_unspecified' => 'উল্লেখ করা হয়নি',
        'country' => 'দেশ',
        'nationality' => 'জাতীয়তা',
        'nationality_placeholder' => 'বাংলাদেশি',
        'referral_code' => 'রেফারেল কোড',
        'referral_placeholder' => 'ঐচ্ছিক',
        'terms' => 'আমি শর্তাবলি মেনে নিচ্ছি',
        'privacy' => 'আমি গোপনীয়তা নীতি মেনে নিচ্ছি',
        'submit' => 'অ্যাকাউন্ট খুলুন',
        'have_account' => 'ইতিমধ্যে অ্যাকাউন্ট আছে?',
        'log_in' => 'লগইন করুন',
    ],

    'forgot' => [
        'title' => 'পাসওয়ার্ড ভুলে গেছেন',
        'description' => 'পাসওয়ার্ড রিসেট লিংক পেতে আপনার ইমেইল দিন',
        'email' => 'ইমেইল ঠিকানা',
        'submit' => 'পাসওয়ার্ড রিসেট লিংক পাঠান',
        'return_to' => 'অথবা ফিরে যান',
        'log_in' => 'লগইনে',
    ],

    'reset' => [
        'title' => 'পাসওয়ার্ড রিসেট',
        'description' => 'নিচে আপনার নতুন পাসওয়ার্ড দিন',
        'email' => 'ইমেইল',
        'password' => 'পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিত করুন',
        'submit' => 'পাসওয়ার্ড রিসেট করুন',
    ],

    'confirm' => [
        'title' => 'পাসওয়ার্ড নিশ্চিত করুন',
        'description' => 'এটি অ্যাপ্লিকেশনের একটি সুরক্ষিত অংশ। এগিয়ে যাওয়ার আগে আপনার পাসওয়ার্ড নিশ্চিত করুন।',
        'passkey' => 'পাসকি দিয়ে নিশ্চিত করুন',
        'passkey_loading' => 'নিশ্চিত করা হচ্ছে...',
        'separator' => 'অথবা পাসওয়ার্ড দিয়ে নিশ্চিত করুন',
        'password' => 'পাসওয়ার্ড',
        'submit' => 'পাসওয়ার্ড নিশ্চিত করুন',
    ],

    'two_factor' => [
        'title' => 'টু-ফ্যাক্টর অথেনটিকেশন',
        'code_title' => 'অথেনটিকেশন কোড',
        'code_description' => 'আপনার অথেনটিকেটর অ্যাপ থেকে পাওয়া কোডটি লিখুন।',
        'code_toggle' => 'রিকভারি কোড দিয়ে লগইন করুন',
        'recovery_title' => 'রিকভারি কোড',
        'recovery_description' => 'আপনার জরুরি রিকভারি কোডগুলোর একটি লিখে অ্যাকাউন্টে প্রবেশ নিশ্চিত করুন।',
        'recovery_toggle' => 'অথেনটিকেশন কোড দিয়ে লগইন করুন',
        'recovery_placeholder' => 'রিকভারি কোড লিখুন',
        'or_you_can' => 'অথবা আপনি',
        'submit' => 'চালিয়ে যান',
    ],

    'verify_email' => [
        'layout_description' => 'অ্যাকাউন্ট প্রস্তুত হতে আর একটি ধাপ বাকি',
    ],
];
