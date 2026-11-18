<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Invoice;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Laravel\Sanctum\Sanctum;

/*
 * The business's own invoices on the mobile API (§8.2): the same list and the
 * same itemised invoice as Settings → Invoices on the web, self-scoped.
 */

function mobileInvoicePayment(BusinessAccount $account, string $key): void
{
    app(RecordPaymentFromQuote::class)->handle(
        account: $account,
        quote: new ActivationQuote([
            new QuoteLine(AllocationType::RenewalFee, Money::fromDecimal('4000.00', Currency::BDT), 'Growth package renewal'),
            new QuoteLine(AllocationType::Discount, Money::fromDecimal('500.00', Currency::BDT), 'Loyalty credit'),
            new QuoteLine(AllocationType::Tax, Money::fromDecimal('525.00', Currency::BDT), 'VAT (15%)'),
        ], Currency::BDT),
        purpose: PaymentPurpose::PackageRenewal,
        idempotencyKey: $key,
    );
}

it('lists the account\'s invoices, newest first, with whether each is paid', function () {
    $account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
    mobileInvoicePayment($account, 'renewal:one');

    Sanctum::actingAs($account->owner);

    $this->getJson(route('mobile.v1.invoices.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_paid', false)
        ->assertJsonPath('data.0.purpose', 'package_renewal')
        ->assertJsonStructure(['data' => [['id', 'number', 'purpose_label', 'total' => ['amount', 'currency', 'formatted'], 'issued_at', 'paid_at']], 'links', 'meta']);
});

it('shows one invoice itemised, with its tax', function () {
    $account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
    mobileInvoicePayment($account, 'renewal:one');
    $invoice = Invoice::query()->sole();

    Sanctum::actingAs($account->owner);

    $this->getJson(route('mobile.v1.invoices.show', $invoice->public_id))
        ->assertOk()
        ->assertJsonPath('data.number', $invoice->number)
        ->assertJsonPath('data.subtotal.amount', '4000.00')
        ->assertJsonCount(3, 'data.lines')
        ->assertJsonStructure(['data' => ['lines' => [['type', 'label', 'amount', 'is_deduction']], 'tax', 'order']]);
});

it('is open before activation, so an applicant can read what they were billed', function () {
    $account = testBusinessAccount(AccountStatus::PaymentPending);
    mobileInvoicePayment($account, 'activation:one');

    Sanctum::actingAs($account->owner);

    $this->getJson(route('mobile.v1.invoices.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never finds another business\'s invoice', function () {
    $mine = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
    $stranger = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
    mobileInvoicePayment($stranger, 'renewal:theirs');
    $theirs = Invoice::query()->sole();

    Sanctum::actingAs($mine->owner);

    $this->getJson(route('mobile.v1.invoices.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('mobile.v1.invoices.show', $theirs->public_id))->assertNotFound();
});

it('is closed without a token', function () {
    $this->getJson(route('mobile.v1.invoices.index'))->assertUnauthorized();
});
