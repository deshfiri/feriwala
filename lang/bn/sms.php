<?php

return [

    /*
     * Bangla SMS is encoded as UCS-2, which limits a single segment to 70
     * characters rather than 160 — so this is kept tighter than the English
     * version rather than translated word for word (§30.2).
     */

    'mobile_verification' => 'আপনার ফেরিওয়ালা কোড :code। ৫ মিনিটে মেয়াদ শেষ। কাউকে জানাবেন না।',

    'kyc_deadline_missed' => 'কেওয়াইসির সময়সীমা শেষ। অ্যাকাউন্ট ফিরে পেতে কাগজপত্র পাঠান।',

];
