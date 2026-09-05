<?php

return [

    /*
     * Kept short deliberately. An SMS over 160 GSM characters costs a second
     * segment, and the Bangla equivalent is limited to 70 (§30.2 cost tracking).
     */

    'mobile_verification' => 'Your Feriwala verification code is :code. It expires in 5 minutes. Do not share it with anyone.',

    'kyc_deadline_missed' => 'Your Feriwala verification deadline has passed. Send your documents to restore your account.',

];
