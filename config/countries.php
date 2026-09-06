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
| Adding a market is a line here and nothing else: no migration, no deploy of
| new code paths. Removing one is not — an account or a scope rule may already
| name it, so retire a market by ceasing to sell there rather than by deleting
| the code that existing records point at.
|
| Ordered with Bangladesh first because it is the home market and the default
| for every registration; the rest are alphabetical.
|
*/

return [

    'default' => 'BD',

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

];
