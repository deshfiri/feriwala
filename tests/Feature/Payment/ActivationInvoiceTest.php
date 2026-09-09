<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The activation invoice (P1-50, §5.1, §9).
 *
 * §5.1 is explicit: the registration fee and the package fee are paid together
 * and must appear **separately** on the invoice. A single "activation" line
 * would make "how much registration revenue did we take" unanswerable, and a
 * refund of one of them impossible to describe.
 */

function activationInvoiceCheckout(array $overrides = []): Payment
{
    Http::fake(['*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go'])]);

    test()->actingAs(test()->applicant)->post(route('packages.select', test()->package));

    if (isset($overrides['coupon'])) {
        test()->actingAs(test()->applicant)
            ->post(route('checkout.coupon'), ['code' => $overrides['coupon']]);
    }

    test()->actingAs(test()->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

    return Payment::query()->with(['allocations', 'taxLines'])->firstOrFail();
}

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
    ]);
});

describe('what the invoice says', function () {
    it('bills the registration fee and the package fee on their own lines', function () {
        activationInvoiceCheckout();

        $invoice = Invoice::query()->with('lines')->firstOrFail();

        $registration = $invoice->lines->firstWhere('type', 'registration_fee');
        $package = $invoice->lines->firstWhere('type', 'package_fee');

        expect($invoice->lines)->toHaveCount(2)
            ->and($registration?->amount_minor->minorUnits)->toBe(100000)
            ->and($package?->amount_minor->minorUnits)->toBe(500000)
            ->and($package?->label)->toBe('Growth package');
    });

    it('gives a discount its own line rather than netting it off a fee', function () {
        /*
         * §9 stores each amount separately for reports, refunds and revenue
         * analysis. A package fee quietly reduced by a coupon answers none of
         * those, and cannot be refunded as what was actually charged.
         */
        Coupon::create([
            'code' => 'LAUNCH',
            'name' => 'Launch promotion',
            'discount_type' => DiscountType::Percentage,
            'value' => 1000,
            'currency_code' => 'BDT',
            'applies_to' => CouponScope::Fees,
            'per_account_limit' => 1,
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        activationInvoiceCheckout(['coupon' => 'LAUNCH']);

        $invoice = Invoice::query()->with('lines')->firstOrFail();
        $discount = $invoice->lines->firstWhere('type', 'discount');

        expect($discount?->amount_minor->minorUnits)->toBe(60000)
            ->and($discount?->is_deduction)->toBeTrue()
            // The fees are still billed in full beside it.
            ->and($invoice->lines->firstWhere('type', 'package_fee')?->amount_minor->minorUnits)
            ->toBe(500000);
    });

    it('gives tax its own line and its own per-rate breakdown', function () {
        // D19 requires a tax breakdown on the invoice: which rate, on what.
        TaxRate::factory()->create();
        TaxRule::factory()->create();

        activationInvoiceCheckout();

        $invoice = Invoice::query()->with('lines')->firstOrFail();

        expect($invoice->lines->firstWhere('type', 'tax')?->amount_minor->minorUnits)->toBe(90000)
            ->and($invoice->total_minor->minorUnits)->toBe(690000);

        $this->actingAs($this->applicant)
            ->get(route('subscription.invoices.show', $invoice->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoice.tax', 1)
                ->where('invoice.tax.0.rate', '15%')
                ->where('invoice.tax.0.net.minor_units', 600000)
                ->where('invoice.tax.0.tax.minor_units', 90000),
            );
    });
});

describe('reconciliation', function () {
    it('agrees exactly with the quote and the payment', function () {
        /*
         * The one arithmetic invariant this whole batch rests on: what the
         * applicant was shown, what was recorded, and what the document says
         * are the same number. §28.1 goes looking for the day they are not.
         */
        $payment = activationInvoiceCheckout();
        $invoice = Invoice::query()->with('lines')->firstOrFail();

        $lineTotal = $invoice->lines->reduce(
            fn (int $carry, InvoiceLine $line) => $line->is_deduction
                ? $carry - $line->amount_minor->minorUnits
                : $carry + $line->amount_minor->minorUnits,
            0,
        );

        $shown = $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->viewData('page')['props']['quote']['total']['minor_units'];

        expect($invoice->total_minor->minorUnits)->toBe($payment->amount_minor->minorUnits)
            ->and($lineTotal)->toBe($payment->amount_minor->minorUnits)
            ->and($shown)->toBe($payment->amount_minor->minorUnits)
            ->and($payment->allocationsBalance())->toBeTrue();
    });

    it('still reconciles once a discount and tax are in play', function () {
        TaxRate::factory()->create();
        TaxRule::factory()->create();

        Coupon::create([
            'code' => 'LAUNCH',
            'name' => 'Launch promotion',
            'discount_type' => DiscountType::Fixed,
            'value' => 75000,
            'currency_code' => 'BDT',
            'applies_to' => CouponScope::Fees,
            'per_account_limit' => 1,
            'effective_from' => now()->subDay(),
            'is_active' => true,
        ]);

        $payment = activationInvoiceCheckout(['coupon' => 'LAUNCH']);
        $invoice = Invoice::query()->with('lines')->firstOrFail();

        $lineTotal = $invoice->lines->reduce(
            fn (int $carry, InvoiceLine $line) => $line->is_deduction
                ? $carry - $line->amount_minor->minorUnits
                : $carry + $line->amount_minor->minorUnits,
            0,
        );

        // 600,000 fees − 75,000 = 525,000 taxable; 15% = 78,750.
        expect($payment->amount_minor->minorUnits)->toBe(603750)
            ->and($lineTotal)->toBe(603750)
            ->and($invoice->total_minor->minorUnits)->toBe(603750);
    });

    it('keeps saying what it said after the fee changes', function () {
        // The document somebody already has cannot move under them.
        activationInvoiceCheckout();
        $invoice = Invoice::query()->with('lines')->firstOrFail();

        app(SettingsRepository::class)->set('billing.registration_fee', 250000);

        expect($invoice->fresh()->load('lines')->lines->firstWhere('type', 'registration_fee')
            ?->amount_minor->minorUnits)->toBe(100000);
    });
});

describe('reaching it', function () {
    it('is there for an applicant who has not paid yet', function () {
        /*
         * The invoice is issued when the payment is **recorded**. An applicant
         * asked for money has the itemised document explaining it before they
         * pay, not after — which is the only time it is any use to them.
         */
        activationInvoiceCheckout();

        expect($this->account->fresh()->status)->toBe(AccountStatus::PaymentPending);

        $this->actingAs($this->applicant)
            ->get(route('subscription.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/invoices')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.is_paid', false),
            );
    });

    it('grants nothing while it is unpaid', function () {
        // §8.2. A document is not an entitlement.
        activationInvoiceCheckout();

        expect(Invoice::query()->with('payment')->firstOrFail()->isPaid())->toBeFalse()
            ->and($this->account->fresh()->status)->toBe(AccountStatus::PaymentPending);
    });

    it('cannot be reached through somebody else\'s account', function () {
        activationInvoiceCheckout();
        $invoice = Invoice::query()->firstOrFail();

        $stranger = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($stranger->owner)
            ->get(route('subscription.invoices.show', $invoice->public_id))
            ->assertNotFound();
    });
});
