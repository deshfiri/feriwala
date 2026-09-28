<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\SetWebsiteProductPublication;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * The consequences bite in the server-side actions, not in the interface
 * (§7.4).
 *
 * Each test drives the real production action, because a restriction that
 * only hides a button is not a restriction: the route is still there, the
 * Storefront API is still there, and the person most likely to find them is
 * the one the restriction is aimed at.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->product = websiteTestProduct();

    KycSubmission::create([
        'business_account_id' => $this->account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);
});

/** @param array<int, KycConsequence> $consequences */
function enforcementTestRound(array $consequences): KycSubmission
{
    return app(RequestKycUpdate::class)->handle(
        test()->account,
        test()->officer,
        'Trade licence expired.',
        'Please upload your renewed trade licence.',
        now()->addDays(7)->toImmutable(),
        null,
        $consequences,
    );
}

function enforcementTestSelection(WebsiteProductStatus $status = WebsiteProductStatus::Selected): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => test()->website->id,
        'business_account_id' => test()->account->id,
        'product_id' => test()->product->id,
        'status' => $status,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('1300.00', Currency::BDT),
        'published_at' => $status === WebsiteProductStatus::Published ? now() : null,
    ]);
}

function enforcementTestPlaceOrder(): array
{
    $unit = Money::fromDecimal('1300.00', Currency::BDT);

    return app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-'.Str::upper(Str::random(8)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Karim Hossain', mobile: '+8801'.random_int(100000000, 999999999)),
        shippingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
        billingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
        items: [['sku' => test()->product->sku, 'quantity' => 1]],
        claimedUnitPrices: [$unit],
        claimedTotals: [
            'subtotal' => $unit,
            'discount' => Money::zero(Currency::BDT),
            'shipping' => Money::zero(Currency::BDT),
            'tax' => Money::zero(Currency::BDT),
            'grand_total' => $unit,
        ],
        paymentMethod: 'online',
    ));
}

describe('warning only', function () {
    it('permits publishing', function () {
        enforcementTestRound([KycConsequence::WarningOnly]);

        $published = app(SetWebsiteProductPublication::class)
            ->publish(enforcementTestSelection(), $this->account->owner);

        expect($published->status)->toBe(WebsiteProductStatus::Published);
    });
});

describe('blocking new orders', function () {
    it('refuses a website order without revealing anything about KYC', function () {
        enforcementTestRound([KycConsequence::BlockNewOrders]);

        try {
            enforcementTestPlaceOrder();
            $this->fail('The order should have been refused.');
        } catch (WebsiteOrderRefused $refused) {
            /*
             * The same answer a paused shop gives. This path is reached by a
             * partner's own website and ultimately their customer, and
             * neither is entitled to learn the merchant is under
             * verification.
             */
            $message = mb_strtolower($refused->getMessage());

            foreach (['kyc', 'verification', 'document', 'licence', 'license'] as $leak) {
                expect($message)->not->toContain($leak);
            }
        }
    });

    it('stops blocking once the case is approved', function () {
        /*
         * Asserted as "the gate is open", not "the order succeeds": placing a
         * real dropshipping order needs a published selection, priced stock
         * and a gateway, none of which this test is about. What matters is
         * that the refusal is no longer the KYC one — the order now fails, if
         * at all, for an ordinary commercial reason.
         */
        enforcementTestSelection(WebsiteProductStatus::Published);

        $round = enforcementTestRound([KycConsequence::BlockNewOrders]);

        $round->forceFill([
            'status' => KycStatus::Approved,
            'reviewed_at' => now(),
        ])->save();

        try {
            enforcementTestPlaceOrder();
        } catch (WebsiteOrderRefused $refused) {
            expect($refused->errorCode)->not->toBe('not_accepting_orders');
        }

        expect(app(KycRestrictions::class)->blocksNewOrders($this->account->refresh()))->toBeFalse();
    });
});

describe('blocking publishing', function () {
    it('refuses a new publication', function () {
        enforcementTestRound([KycConsequence::BlockPublishing]);

        expect(fn () => app(SetWebsiteProductPublication::class)
            ->publish(enforcementTestSelection(), $this->account->owner))
            ->toThrow(WebsiteRefused::class);
    });

    it('leaves an already published product on the storefront', function () {
        // Blocking new activity never removes old activity. Taking an
        // account's shop down over a document would be a punishment out of
        // all proportion to the question.
        $live = enforcementTestSelection(WebsiteProductStatus::Published);

        enforcementTestRound([KycConsequence::BlockPublishing]);

        expect($live->fresh()->status)->toBe(WebsiteProductStatus::Published)
            ->and($live->fresh()->published_at)->not->toBeNull();
    });

    it('does not block publishing when only orders are blocked', function () {
        enforcementTestRound([KycConsequence::BlockNewOrders]);

        $published = app(SetWebsiteProductPublication::class)
            ->publish(enforcementTestSelection(), $this->account->owner);

        expect($published->status)->toBe(WebsiteProductStatus::Published);
    });
});
