<?php

return [
    'title' => 'অ্যাকাউন্ট',
    'description' => 'প্ল্যাটফর্মে নিবন্ধিত সব ক্লায়েন্ট ও পার্টনার ব্যবসা।',
    'search_placeholder' => 'অ্যাকাউন্ট আইডি, ব্যবসা, মালিক, ইমেইল বা মোবাইল',
    'open' => 'খুলুন',
    'verified' => 'যাচাই করা',
    'unverified' => 'যাচাই করা হয়নি',
    'empty_title' => 'কোনো অ্যাকাউন্ট মেলেনি',
    'empty_description' => 'অন্যভাবে খুঁজুন অথবা ফিল্টার মুছে দিন।',

    'summary' => [
        'kyc_pending' => 'কেওয়াইসি অপেক্ষমাণ',
        'payment_pending' => 'পেমেন্ট অপেক্ষমাণ',
        'approval_pending' => 'অনুমোদন অপেক্ষমাণ',
        'active' => 'সক্রিয়',
        'suspended' => 'স্থগিত',
        'expired' => 'মেয়াদোত্তীর্ণ',
        'reverification_required' => 'পুনঃযাচাই',
    ],

    'columns' => [
        'business' => 'ব্যবসা',
        'contact' => 'যোগাযোগ',
        'package' => 'প্যাকেজ',
        'status' => 'অবস্থা',
        'registered' => 'নিবন্ধিত',
        'activated' => 'সক্রিয় হয়েছে',
    ],

    'filters' => [
        'state' => 'যেকোনো অবস্থা',
        'facility' => 'যেকোনো সুবিধা',
        'reverification' => 'যেকোনো পুনঃযাচাই',
        'package' => 'যেকোনো প্যাকেজ',
        'wallet' => 'যেকোনো ওয়ালেট অবস্থা',
    ],

    'states' => [
        'trading' => 'ব্যবসারত',
        'onboarding' => 'অনবোর্ডিং চলছে',
        'halted' => 'স্থগিত বা সীমাবদ্ধ',
        'expired' => 'প্যাকেজ মেয়াদোত্তীর্ণ',
        'closed' => 'বন্ধ',
    ],

    'facilities' => [
        'wholesale' => 'পাইকারি',
        'dropshipping' => 'ড্রপশিপিং',
        'both' => 'পাইকারি ও ড্রপশিপিং',
    ],

    'reverification' => [
        'required' => 'পুনঃযাচাই প্রয়োজন',
        'overdue' => 'পুনঃযাচাই সময়োত্তীর্ণ',
        'none' => 'পুনঃযাচাই বাকি নেই',
    ],

    'wallet' => [
        'restricted' => 'ওয়ালেট সীমাবদ্ধ',
    ],
];
