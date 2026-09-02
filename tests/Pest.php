<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Groups
|--------------------------------------------------------------------------
|
| Groups let a slice be run on its own — `pest --group=wallet` while working on
| the ledger, rather than the whole suite. They are declared here so the names
| stay consistent instead of being invented per file.
|
| Several exist because requirements.txt §43 names them explicitly and they are
| the ones most likely to be skipped otherwise: concurrency, self-scoped data
| access, and the product creation restrictions of §12.
|
*/

pest()->group('onboarding')->in('Feature/Onboarding');
pest()->group('kyc')->in('Feature/Kyc');
pest()->group('package')->in('Feature/Package');
pest()->group('payment')->in('Feature/Payment');
pest()->group('wallet')->in('Feature/Wallet');
pest()->group('ledger')->in('Feature/Ledger');
pest()->group('commission')->in('Feature/Commission');
pest()->group('referral')->in('Feature/Referral');
pest()->group('withdrawal')->in('Feature/Withdrawal');
pest()->group('catalog')->in('Feature/Catalog');
pest()->group('product-restrictions')->in('Feature/ProductRestrictions');
pest()->group('inventory')->in('Feature/Inventory');
pest()->group('wholesale')->in('Feature/Wholesale');
pest()->group('dropshipping')->in('Feature/Dropshipping');
pest()->group('website-api')->in('Feature/WebsiteApi');
pest()->group('orders')->in('Feature/Orders');
pest()->group('fulfillment')->in('Feature/Fulfillment');
pest()->group('courier')->in('Feature/Courier');
pest()->group('settlement')->in('Feature/Settlement');
pest()->group('reports')->in('Feature/Reports');
pest()->group('permissions')->in('Feature/Permissions');
pest()->group('self-scope')->in('Feature/SelfScope');
pest()->group('security')->in('Feature/Security');
pest()->group('concurrency')->in('Feature/Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
