<?php

/*
|--------------------------------------------------------------------------
| Supported countries
|--------------------------------------------------------------------------
|
| ISO 3166-1 alpha-2 code => English name.
|
| A curated list rather than all 249 codes. Every entry here is a country a
| Feriwala account can be scoped to — for KYC requirements (§7.2), addresses
| (§5.2) and, later, courier and tax rules. A picker showing 249 options is a
| picker nobody finds "Bangladesh" in, and most of them would be wrong answers.
|
| Ordered with Bangladesh first because it is the home market and the default
| for every registration; the rest are alphabetical.
|
|--------------------------------------------------------------------------
| Codes are permanent
|--------------------------------------------------------------------------
|
| A code that has ever been stored must stay resolvable. Addresses, KYC scope
| rules, courier zones and historical records all persist the code, so deleting
| a line here does not remove a market — it turns every row that names it into
| data nothing can read, silently and without an error anywhere.
|
| To stop selling into a market, move its code to `retired` below. It then:
|
|   - disappears from every picker, so nothing new can be scoped to it;
|   - stays resolvable, so an existing address still renders its name and an
|     existing KYC rule still matches the accounts it was written for.
|
| Renaming is likewise not a deletion: change the label freely, never the code.
|
*/

return [

    'default' => 'BD',

    /*
     * Selectable now.
     */
    'supported' => [
        'BD' => 'Bangladesh',

        // South Asia
        'BT' => 'Bhutan',
        'IN' => 'India',
        'LK' => 'Sri Lanka',
        'MV' => 'Maldives',
        'NP' => 'Nepal',
        'PK' => 'Pakistan',

        // The Gulf — where much of the diaspora trade sits
        'AE' => 'United Arab Emirates',
        'BH' => 'Bahrain',
        'KW' => 'Kuwait',
        'OM' => 'Oman',
        'QA' => 'Qatar',
        'SA' => 'Saudi Arabia',

        // South-East and East Asia — sourcing and logistics
        'CN' => 'China',
        'HK' => 'Hong Kong',
        'ID' => 'Indonesia',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'MY' => 'Malaysia',
        'PH' => 'Philippines',
        'SG' => 'Singapore',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',

        // Major export destinations
        'AU' => 'Australia',
        'CA' => 'Canada',
        'DE' => 'Germany',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'IT' => 'Italy',
        'NL' => 'Netherlands',
        'ES' => 'Spain',
        'US' => 'United States',
    ],

    /*
     * No longer offered, still readable.
     *
     * Markets Feriwala has stopped serving. Nothing new may be scoped to one,
     * and everything already scoped to one keeps working — which is the whole
     * point of retiring a code rather than deleting it.
     */
    'retired' => [
        // 'XX' => 'Former market',
    ],

];
