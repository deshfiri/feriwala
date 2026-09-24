<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\ProcessRefund;
use App\Domain\Billing\Actions\SettleRefund;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Exceptions\RefundRefused;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Billing\Queries\RefundableAmount;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Actions\CalculateReferralCommissions;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Sending an approved refund back through the gateway (P2-31, §26.3, D17).
 *
 * D17's workflow already covered asking and deciding. This is the half
 * DecideRefund deliberately left for later — "an approval moves no money on its
 * own" — and the rules it has to hold to:
 *
 *   - a refund is a new operation, never an edit to the payment;
 *   - never more than what is still refundable, even under two administrators;
 *   - provider confirmation before anything is reversed;
 *   - never a silently negative wallet.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
    $this->account = testBusinessAccount(AccountStatus::Active);

    $this->payment = Payment::create([
        'business_account_id' => $this->account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'gateway' => 'sslcommerz',
        'gateway_reference' => 'val-1',
        'gateway_settlement_reference' => 'BANK-1',
        'completed_at' => now(),
    ]);
});

/**
 * An approved refund, ready to send.
 */
function approvedRefund(
    Payment $payment,
    string $amount = '6000.00',
    AllocationType $type = AllocationType::PackageFee,
): RefundRequest {
    return RefundRequest::factory()
        ->status(RefundStatus::Approved)
        ->create([
            'payment_id' => $payment->id,
            'business_account_id' => $payment->business_account_id,
            'allocation_type' => $type,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal($amount, Currency::BDT),
        ]);
}

/**
 * SSLCommerz's answers, in the order they will be asked for.
 *
 * A sequence rather than repeated `Http::fake()` calls: faking twice **merges**
 * the stubs and the first match wins, so a second `fake()` for the same URL
 * never takes effect — and every SSLCommerz call goes to the same URL.
 *
 * @param  array<int, array<string, mixed>>  $responses
 */
function refundAnswers(array $responses): void
{
    $sequence = Http::fakeSequence();

    foreach ($responses as $response) {
        $sequence->push(['APIConnect' => 'DONE', ...$response]);
    }
}

/**
 * The provider accepting a refund instruction.
 *
 * @return array<string, mixed>
 */
function refundAcceptance(string $reference = 'REF-001'): array
{
    return ['status' => 'success', 'refund_ref_id' => $reference];
}

/**
 * The provider later confirming the money went back.
 *
 * @return array<string, mixed>
 */
function refundSettled(): array
{
    return ['status' => 'refunded'];
}

/**
 * A wallet for an account, and money in it.
 */
function walletWith(string $credit, string $key): Wallet
{
    $wallet = Wallet::query()
        ->where('business_account_id', test()->account->id)
        ->firstOr(fn () => Wallet::create([
            'business_account_id' => test()->account->id,
            'currency_code' => 'BDT',
        ]));

    if ($credit !== '0.00') {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::fromDecimal($credit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Top-up', idempotencyKey: $key),
        );
    }

    return $wallet->refresh();
}

/**
 * A settled top-up, which is one of the two purposes that credit a wallet.
 */
function topUpPayment(string $amount, string $reference): Payment
{
    return Payment::create([
        'business_account_id' => test()->account->id,
        'purpose' => PaymentPurpose::WalletTopUp,
        'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal($amount, Currency::BDT),
        'gateway' => 'sslcommerz',
        'gateway_reference' => $reference,
        'completed_at' => now(),
    ]);
}

function processRefund(RefundRequest $refund, bool $twoFactor = true): RefundRequest
{
    return app(ProcessRefund::class)->handle(
        request: $refund,
        actor: test()->manager,
        passwordConfirmed: true,
        twoFactorEnabled: $twoFactor,
    );
}

describe('only an approved decision is sent', function () {
    it('refuses a refund nobody has decided yet', function () {
        // D17 makes every refund an administrator's decision. There is
        // deliberately no path that both asks and grants in one step.
        $refund = RefundRequest::factory()->create([
            'payment_id' => $this->payment->id,
            'business_account_id' => $this->account->id,
        ]);

        Http::fake();

        expect(fn () => processRefund($refund))
            ->toThrow(RefundRefused::class, 'Only an approved refund');

        Http::assertNothingSent();
    });

    it('refuses to send one that has already gone back', function () {
        $refund = approvedRefund($this->payment);
        $refund->transitionTo(RefundStatus::Processed)->save();

        Http::fake();

        expect(fn () => processRefund($refund))->toThrow(RefundRefused::class);

        Http::assertNothingSent();
    });

    it('refuses when the payment never settled', function () {
        $this->payment->forceFill(['status' => PaymentStatus::Initiated])->save();

        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => processRefund($refund))
            ->toThrow(RefundRefused::class, 'Only money that actually arrived');
    });
});

describe('the §32.2 escalation', function () {
    it('refuses somebody without the reversal permission', function () {
        $viewer = testPlatformStaff(PlatformRole::FinanceManager);

        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => app(ProcessRefund::class)->handle(
            request: $refund,
            actor: $viewer,
            passwordConfirmed: true,
            twoFactorEnabled: true,
        ))->toThrow(SensitiveActionRefused::class);

        Http::assertNothingSent();
    });

    it('refuses without a freshly confirmed password', function () {
        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => app(ProcessRefund::class)->handle(
            request: $refund,
            actor: $this->manager,
            passwordConfirmed: false,
            twoFactorEnabled: true,
        ))->toThrow(SensitiveActionRefused::class);

        Http::assertNothingSent();
    });

    it('refuses without two-factor, because a password alone is a stolen session', function () {
        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => processRefund($refund, twoFactor: false))
            ->toThrow(SensitiveActionRefused::class);

        Http::assertNothingSent();
    });

    it('is enforced in the action, not only the controller', function () {
        /*
         * The guard runs inside ProcessRefund, so reaching it from a job, a
         * command or a second controller cannot skip the checks.
         */
        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => app(ProcessRefund::class)->handle(
            request: $refund,
            actor: $this->manager,
            passwordConfirmed: false,
            twoFactorEnabled: false,
        ))->toThrow(SensitiveActionRefused::class);
    });
});

describe('provider confirmation before any reversal', function () {
    it('does not complete on acceptance alone', function () {
        /*
         * SSLCommerz answers `success` on acceptance, which its own vocabulary
         * separates from settlement — so the driver reports pending and the
         * refund stays approved. Only `refunded` on a later query completes it,
         * and until then nothing has been reversed.
         */
        refundAnswers([refundAcceptance()]);

        $refund = processRefund(approvedRefund($this->payment));

        expect($refund->status)->toBe(RefundStatus::Approved)
            ->and($refund->gateway_refund_reference)->toBe('REF-001')
            ->and($refund->processed_at)->toBeNull()
            ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('completes only when the provider says the money went back', function () {
        refundAnswers([refundAcceptance(), refundSettled()]);

        $refund = processRefund(approvedRefund($this->payment));

        // The sweep, asking SSLCommerz what became of it.
        $settled = app(SettleRefund::class)->handle($refund);

        expect($settled->status)->toBe(RefundStatus::Processed)
            ->and($settled->processed_at)->not->toBeNull()
            ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Refunded);
    });

    it('takes back the referral commissions a refunded activation paid, once the money went back (D24)', function () {
        [$referrer] = referralTestChain(1);
        app(AttachReferrer::class)->atRegistration($this->account, $referrer, null);
        $this->payment->allocations()->create(['type' => AllocationType::RegistrationFee, 'currency_code' => 'BDT', 'amount' => Money::fromDecimal('1000.00', Currency::BDT)]);
        $this->payment->allocations()->create(['type' => AllocationType::PackageFee, 'currency_code' => 'BDT', 'amount' => Money::fromDecimal('5000.00', Currency::BDT)]);

        referralTestSwitchOn();
        referralTestPlan([['percentage', '10']]);
        app(CalculateReferralCommissions::class)->forActivation($this->account);

        $commission = ReferralCommission::query()->firstOrFail();

        expect($commission->status)->toBe(CommissionStatus::Paid);

        refundAnswers([refundAcceptance(), refundSettled()]);
        $refund = processRefund(approvedRefund($this->payment));

        // Accepted is not enough: nothing is taken back yet.
        expect($commission->refresh()->status)->toBe(CommissionStatus::Paid);

        app(SettleRefund::class)->handle($refund);

        expect($commission->refresh()->status)->toBe(CommissionStatus::Reversed)
            ->and($commission->reversal_cause)->toBe(ReversalCause::Refund)
            ->and(Wallet::query()->where('business_account_id', $referrer->id)->firstOrFail()->total->toDecimal())->toBe('0.00');
    });

    it('leaves an in-flight refund exactly where it was', function () {
        refundAnswers([refundAcceptance(), ['status' => 'processing']]);

        $refund = processRefund(approvedRefund($this->payment));

        expect(app(SettleRefund::class)->handle($refund)->status)->toBe(RefundStatus::Approved)
            ->and($this->payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('marks a refused refund failed and frees the amount again', function () {
        refundAnswers([['status' => 'failed', 'errorReason' => 'Refund window has closed']]);

        $refund = processRefund(approvedRefund($this->payment));

        expect($refund->status)->toBe(RefundStatus::Failed)
            ->and($refund->failure_reason)->toBe('Refund window has closed')

            // The decision stands and the money is refundable again, without
            // anything having had to remember to release it.
            ->and(app(RefundableAmount::class)->handle($this->payment)->toDecimal())->toBe('6000.00');
    });

    it('leaves the refund sendable when the gateway cannot be reached', function () {
        // Could not ask. Nothing is reversed on the strength of a call that
        // never completed, and the refund can be sent again under the same key.
        Http::fake(['*' => Http::response('', 503)]);

        $refund = processRefund(approvedRefund($this->payment));

        expect($refund->status)->toBe(RefundStatus::Approved)
            ->and($refund->idempotency_key)->not->toBeNull()
            ->and(PaymentLog::query()->where('outcome', 'unavailable')->exists())->toBeTrue();
    });
});

describe('never more than what is left', function () {
    it('refuses a refund larger than the payment', function () {
        $refund = approvedRefund($this->payment, amount: '7000.00');

        Http::fake();

        expect(fn () => processRefund($refund))
            ->toThrow(RefundRefused::class, 'still refundable');

        Http::assertNothingSent();
    });

    it('counts an approved refund against what is left', function () {
        /*
         * Two decisions totalling more than the payment cannot both be sent.
         * The second reads what the first one claimed — which is why approved,
         * not just processed, holds the amount.
         */
        approvedRefund($this->payment, amount: '4000.00', type: AllocationType::PackageFee);

        expect(app(RefundableAmount::class)->handle($this->payment)->toDecimal())->toBe('2000.00');

        $second = approvedRefund($this->payment, amount: '4000.00', type: AllocationType::RegistrationFee);

        Http::fake();

        expect(fn () => processRefund($second))
            ->toThrow(RefundRefused::class, 'still refundable');
    });

    it('allows two partial refunds that fit', function () {
        refundAnswers([refundAcceptance('REF-A'), refundAcceptance('REF-B')]);

        $first = processRefund(approvedRefund($this->payment, '2000.00', AllocationType::RegistrationFee));
        $second = processRefund(approvedRefund($this->payment, '4000.00', AllocationType::PackageFee));

        expect($first->gateway_refund_reference)->toBe('REF-A')
            ->and($second->gateway_refund_reference)->toBe('REF-B')
            ->and(app(RefundableAmount::class)->handle($this->payment)->toDecimal())->toBe('0.00');
    });

    it('reports a payment as partially refunded until all of it has gone back', function () {
        refundAnswers([
            refundAcceptance('REF-A'),
            refundSettled(),
            refundAcceptance('REF-B'),
            refundSettled(),
        ]);

        $first = processRefund(approvedRefund($this->payment, '2000.00', AllocationType::RegistrationFee));
        app(SettleRefund::class)->handle($first);

        expect($this->payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

        $second = processRefund(approvedRefund($this->payment, '4000.00', AllocationType::PackageFee));
        app(SettleRefund::class)->handle($second);

        expect($this->payment->refresh()->status)->toBe(PaymentStatus::Refunded);
    });
});

describe('a refund is a new operation, not an edit', function () {
    it('never changes what the payment says was taken', function () {
        refundAnswers([refundAcceptance(), refundSettled()]);

        $refund = processRefund(approvedRefund($this->payment));
        app(SettleRefund::class)->handle($refund);

        $this->payment->refresh();

        // The status moved. The figures did not.
        expect($this->payment->amount->toDecimal())->toBe('6000.00')
            ->and($this->payment->gateway_reference)->toBe('val-1')
            ->and($this->payment->completed_at)->not->toBeNull()
            ->and($this->payment->status)->toBe(PaymentStatus::Refunded);
    });

    it('carries the same idempotency key when it is sent again', function () {
        // A provider that took the first instruction recognises the second as
        // the same one rather than refunding twice.
        Http::fake(['*' => Http::response('', 503)]);

        $refund = processRefund(approvedRefund($this->payment));
        $key = $refund->idempotency_key;

        refundAnswers([refundAcceptance()]);

        $again = processRefund($refund->refresh());

        expect($again->idempotency_key)->toBe($key);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'merchantTransIDvalidation')
            || $request['refund_trans_id'] === $key);
    });
});

describe('the wallet is never silently overdrawn', function () {
    it('refuses to refund a top-up the account has already spent', function () {
        /*
         * The obvious failure this guards against: a top-up is credited, the
         * account spends it, and somebody refunds the top-up. Taking it back
         * would leave a negative available balance nobody authorised.
         */
        $topUp = topUpPayment('5000.00', 'val-topup');
        $wallet = walletWith('5000.00', 'test:topup');

        // Spent on something else.
        app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('4500.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Service', idempotencyKey: 'test:spend'),
        );

        Http::fake();

        expect(fn () => processRefund(approvedRefund($topUp, amount: '5000.00')))
            ->toThrow(RefundRefused::class, 'overdraw this wallet');

        // Refused before the provider was asked, not after the money had gone.
        Http::assertNothingSent();
    });

    it('takes the money back out when the wallet can carry it', function () {
        $topUp = topUpPayment('5000.00', 'val-topup-2');
        $wallet = walletWith('5000.00', 'test:topup-2');

        $before = $wallet->total->toDecimal();

        refundAnswers([refundAcceptance('REF-TOPUP'), refundSettled()]);

        $refund = processRefund(approvedRefund($topUp, amount: '5000.00'));
        $settled = app(SettleRefund::class)->handle($refund);

        expect($settled->status)->toBe(RefundStatus::Processed)
            ->and($settled->wallet_transaction_id)->not->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe(bcsub($before, '5000.00', 2));
    });

    it('does not touch a wallet for a payment that never credited one', function () {
        // An activation fee is money paid *to* Feriwala. Refunding it reverses
        // nothing in a wallet, because nothing was ever credited to one.
        $wallet = walletWith('2000.00', 'test:unrelated');
        $before = $wallet->total->toDecimal();

        refundAnswers([refundAcceptance(), refundSettled()]);

        $refund = processRefund(approvedRefund($this->payment));
        $settled = app(SettleRefund::class)->handle($refund);

        expect($settled->status)->toBe(RefundStatus::Processed)
            ->and($settled->wallet_transaction_id)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe($before);
    });

    it('reverses a wallet exactly once however often the confirmation arrives', function () {
        $topUp = topUpPayment('3000.00', 'val-topup-3');
        $wallet = walletWith('3000.00', 'test:topup-3');

        $before = $wallet->total->toDecimal();

        refundAnswers([
            refundAcceptance('REF-ONCE'),
            refundSettled(),
            refundSettled(),
            refundSettled(),
        ]);

        $refund = processRefund(approvedRefund($topUp, amount: '3000.00'));

        app(SettleRefund::class)->handle($refund->refresh());
        app(SettleRefund::class)->handle($refund->refresh());
        app(SettleRefund::class)->handle($refund->refresh());

        expect($wallet->refresh()->total->toDecimal())->toBe(bcsub($before, '3000.00', 2));
    });
});

describe('what the gateway can do', function () {
    it('refuses a partial refund through a provider that offers none', function () {
        /*
         * Nothing offers a button that calls an endpoint nobody has. aamarPay
         * documents no refund API at all, so a refund through it is refused
         * before anything is sent.
         */
        app(SettingsRepository::class)->define('payment.amarpay.mode', 'payment', SettingType::String, 'sandbox');
        app(SettingsRepository::class)->define('payment.amarpay.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
        app(SettingsRepository::class)->define('payment.amarpay.sandbox.signature_key', 'payment', SettingType::String, 'key', isEncrypted: true);

        $this->payment->forceFill(['gateway' => 'amarpay'])->save();

        $refund = approvedRefund($this->payment);

        Http::fake();

        expect(fn () => processRefund($refund))
            ->toThrow(RefundRefused::class, 'does not support');

        Http::assertNothingSent();
    });
});

describe('the record', function () {
    it('keeps what the provider said, redacted', function () {
        refundAnswers([refundAcceptance()]);

        $refund = processRefund(approvedRefund($this->payment));

        $entry = PaymentLog::query()->where('event', 'refund')->firstOrFail();

        expect($entry->direction)->toBe(PaymentLog::OUTBOUND)
            ->and($entry->amount->toDecimal())->toBe('6000.00');

        // The store password is a parameter on every SSLCommerz call and must
        // not survive into evidence either (§42).
        expect(json_encode($refund->evidence))->not->toContain('pass');
    });

    it('cannot be deleted', function () {
        // The amount already refunded is computed from these rows, so a row
        // somebody can remove is a way of making refunded money disappear.
        $refund = approvedRefund($this->payment);

        expect(fn () => $refund->delete())->toThrow(RuntimeException::class);
    });
});
