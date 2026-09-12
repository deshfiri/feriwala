<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\PaymentLogRedactor;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Payment logs (P1-54, §42).
 *
 * §42 lists what a log may never hold: passwords, security tokens, API secrets,
 * gateway secrets, complete payment credentials, unmasked personal data. A
 * gateway payload contains several of those as a matter of course, so the
 * redaction happens at the one door everything comes through.
 */

function paymentLogSettings(): void
{
    $settings = app(SettingsRepository::class);

    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
}

/**
 * The IPN entry as the administration screen actually ships it.
 *
 * Found by what it is rather than by where it sits: the trail is newest-first
 * and a settlement writes its own outbound entry afterwards, so an index would
 * quietly start pointing at a different exchange.
 *
 * @return array<string, mixed>
 */
function paymentLogShownIpn(User $manager, Payment $payment): array
{
    $response = test()->actingAs($manager)
        ->get(route('admin.payments.show', $payment->public_id));

    $response->assertOk();

    /** @var array<int, array<string, mixed>> $logs */
    $logs = $response->viewData('page')['props']['logs'];

    foreach ($logs as $entry) {
        if ($entry['event'] === 'ipn') {
            return $entry;
        }
    }

    throw new RuntimeException('The screen shipped no inbound IPN entry.');
}

/**
 * @return array<string, string>
 */
function paymentLogSignedIpn(string $reference, string $valId = 'val-1'): array
{
    $fields = ['tran_id' => $reference, 'val_id' => $valId, 'status' => 'VALID'];

    $signed = $fields;
    $signed['store_passwd'] = md5('pass');
    ksort($signed);

    $pairs = [];

    foreach ($signed as $key => $value) {
        $pairs[] = $key.'='.$value;
    }

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', $pairs)),
    ];
}

beforeEach(function () {
    paymentLogSettings();

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
    ]);

    $this->validation = [
        'status' => 'VALID',
        'currency_amount' => '6000.00',
        'currency_type' => 'BDT',
    ];

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'gwprocess')) {
            return Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://pay.test/go',
                'sessionkey' => 'session-1',
            ]);
        }

        return Http::response($this->validation);
    });

    $this->start = function (): Payment {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->firstOrFail();
        $this->validation['tran_id'] = $payment->reference;

        return $payment;
    };
});

describe('redaction', function () {
    it('drops every field §42 forbids', function () {
        $clean = app(PaymentLogRedactor::class)->redact([
            'tran_id' => 'PAY-1',
            'store_passwd' => 'merchant-secret',
            'store_id' => 'feriwala-live',
            'verify_sign' => 'abc123',
            'verify_key' => 'tran_id,status',
            'api_key' => 'k-1',
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'access_token' => 't-1',
        ]);

        expect($clean['tran_id'])->toBe('PAY-1')
            ->and($clean['store_passwd'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['store_id'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['verify_sign'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['verify_key'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['api_key'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['card_number'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['cvv'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['access_token'])->toBe(PaymentLogRedactor::REDACTED);
    });

    it('masks personal data rather than dropping it', function () {
        // A support conversation about a failed payment needs to know which
        // number it went to. The last four digits answer that without the log
        // becoming a phone book.
        $clean = app(PaymentLogRedactor::class)->redact([
            'cus_phone' => '01712345678',
            'cus_email' => 'applicant@example.com',
        ]);

        expect($clean['cus_phone'])->toBe('017****5678')
            ->and($clean['cus_email'])->toBe('a********@example.com');
    });

    it('reaches into nested payloads but not for ever', function () {
        $clean = app(PaymentLogRedactor::class)->redact([
            'meta' => ['store_passwd' => 'secret', 'ok' => 'yes'],
        ]);

        expect($clean['meta']['store_passwd'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($clean['meta']['ok'])->toBe('yes');
    });

    it('truncates a value somebody is trying to fill the table with', function () {
        $clean = app(PaymentLogRedactor::class)->redact(['note' => str_repeat('a', 900)]);

        expect(mb_strlen($clean['note']))->toBe(PaymentLogRedactor::MAX_LENGTH + 1);
    });
});

describe('what gets written', function () {
    it('records the gateway session being opened', function () {
        $payment = ($this->start)();

        $entry = PaymentLog::query()->where('event', 'initiate')->firstOrFail();

        expect($entry->payment_id)->toBe($payment->id)
            ->and($entry->direction)->toBe(PaymentLog::OUTBOUND)
            ->and($entry->outcome)->toBe('session_created')
            ->and($entry->amount_minor)->toBe(600000)
            ->and($entry->currency_code)->toBe('BDT');
    });

    it('records what the gateway actually answered', function () {
        // The authoritative answer is the one worth keeping: the redirect and
        // the webhook are claims, this is what we acted on.
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $entry = PaymentLog::query()->where('event', 'verify')->firstOrFail();

        expect($entry->outcome)->toBe('paid')
            ->and($entry->gateway_reference)->toBe('val-1');
    });

    it('never writes a credential into the table', function () {
        /*
         * The store password is in every request we send and the signature is
         * in every notification we receive. Neither may survive into a log.
         */
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        // "queued" since P2-30: the notification is written down and the
        // verification handed to a job rather than done inside the request.
        $ipn = PaymentLog::query()->where('event', 'ipn')->where('outcome', 'queued')->firstOrFail();

        // The field name survives — it is the value that is the secret.
        expect($ipn->context['verify_sign'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($ipn->context['verify_key'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($ipn->context['tran_id'])->toBe($payment->reference);

        $values = PaymentLog::query()
            ->get()
            ->flatMap(fn (PaymentLog $entry) => array_values($entry->context ?? []))
            ->all();

        expect($values)->not->toContain('pass')
            ->and($values)->not->toContain($ipn->context['tran_id'].'-signature');
    });

    it('keeps an unsigned notification rather than only refusing it', function () {
        // "Somebody has been posting unsigned notifications at us" is a
        // question with an answer only if the refusals are kept.
        $payment = ($this->start)();

        $this->postJson(route('webhooks.payment', 'sslcommerz'), [
            'tran_id' => $payment->reference,
            'val_id' => 'val-1',
            'status' => 'VALID',
        ])->assertStatus(401);

        $entry = PaymentLog::query()->where('outcome', 'refused_signature')->firstOrFail();

        expect($entry->http_status)->toBe(401)
            ->and($entry->payment_id)->toBeNull()
            ->and($entry->ip_address)->not->toBeNull();
    });

    it('keeps a signed notification for a payment nobody recognises', function () {
        // The most interesting row in the table, and the easiest to drop.
        ($this->start)();

        $body = paymentLogSignedIpn('PAY-NOT-OURS');

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();

        $entry = PaymentLog::query()->where('outcome', 'unknown_payment')->firstOrFail();

        expect($entry->reference)->toBe('PAY-NOT-OURS')
            ->and($entry->payment_id)->toBeNull();
    });

    it('records the redirect the applicant was shown', function () {
        $payment = ($this->start)();

        $this->actingAs($this->applicant)
            ->get(route('checkout.cancelled', ['tran_id' => $payment->reference]));

        expect(PaymentLog::query()->where('event', 'cancel')->exists())->toBeTrue();
    });

    it('cannot be edited or deleted', function () {
        // A log somebody can change after the fact is not evidence of anything.
        ($this->start)();

        $entry = PaymentLog::query()->firstOrFail();

        expect(fn () => $entry->forceFill(['outcome' => 'edited'])->save())
            ->toThrow(RuntimeException::class)
            ->and(fn () => $entry->delete())->toThrow(RuntimeException::class);
    });

    it('never breaks the payment it is logging', function () {
        /*
         * A settled payment must not be rolled back because its diary entry
         * could not be written.
         */
        $payment = ($this->start)();

        app(RecordPaymentLog::class)->handle(
            gateway: 'sslcommerz',
            direction: PaymentLog::OUTBOUND,
            // Longer than the column allows: the write fails, the caller does not.
            event: str_repeat('x', 500),
            payment: $payment,
        );

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });
});

describe('the administration screen', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = testPlatformStaff(PlatformRole::PaymentManager);
    });

    it('lists payments with everything needed to chase one', function () {
        $payment = ($this->start)();

        $this->actingAs($this->manager)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/payments/index')
                ->has('payments.data', 1)
                ->where('payments.data.0.reference', $payment->reference)
                ->where('payments.data.0.gateway', 'sslcommerz')
                ->where('payments.data.0.amount.minor_units', 600000)
                ->where('payments.data.0.currency', 'BDT')
                ->where('payments.data.0.status', 'initiated')
                ->has('payments.data.0.status_label')
                ->has('payments.data.0.account')
                ->has('payments.data.0.created_at'),
            );
    });

    it('counts the payments waiting on a person', function () {
        // They must not hide among ordinary failures: a filter you have to know
        // to apply is a filter nobody applies.
        $payment = ($this->start)();

        Payment::query()->update(['expires_at' => now()->subHour()]);
        app(ExpireUnpaidPayments::class)->handle();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $this->actingAs($this->manager)
            ->get(route('admin.payments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('needs_reconciliation', 1)
                ->where('payments.data.0.needs_reconciliation', true)
                // Labelled, not merely coloured (§33.9).
                ->where('payments.data.0.status_label', 'Needs reconciliation'),
            );
    });

    it('filters to only those', function () {
        $payment = ($this->start)();

        Payment::query()->update(['expires_at' => now()->subHour()]);
        app(ExpireUnpaidPayments::class)->handle();
        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        // A second, ordinary payment that must not appear.
        Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => $payment->purpose,
            'status' => PaymentStatus::Draft,
            'amount_minor' => 100,
            'currency_code' => 'BDT',
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.payments.index', ['status' => 'reconciliation']))
            ->assertInertia(fn (Assert $page) => $page->has('payments.data', 1));
    });

    it('shows one payment with its whole gateway trail', function () {
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $this->actingAs($this->manager)
            ->get(route('admin.payments.show', $payment->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/payments/show')
                ->where('payment.reference', $payment->reference)
                ->has('payment.invoice_number')
                ->has('payment.purpose_label')
                // Session opened, notification received, gateway asked.
                ->has('logs', 3),
            );
    });

    it('shows the reconciliation reason on the payment that needs it', function () {
        $payment = ($this->start)();

        Payment::query()->update(['expires_at' => now()->subHour()]);
        app(ExpireUnpaidPayments::class)->handle();
        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $this->actingAs($this->manager)
            ->get(route('admin.payments.show', $payment->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.needs_reconciliation', true)
                ->whereNot('payment.reconciliation_reason', null)
                ->whereNot('payment.reconciliation_required_at', null),
            );
    });

    it('sends no credential to the browser', function () {
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $this->actingAs($this->manager)
            ->get(route('admin.payments.show', $payment->public_id))
            ->assertOk()
            ->assertDontSee('verify_sign', escape: false);
    });

    it('sends no secret field name either, whatever it was called', function () {
        /*
         * The name of a credential is not itself a credential, but a rendered
         * page ends up in tickets, screenshots and chat threads — it travels
         * further than the row it came from. So the field goes entirely, and
         * even the `[redacted]` placeholder the stored row carries stays behind.
         *
         * Asserted against the shipped payload rather than the whole page,
         * because `store_id` is also the name of a field on the gateway
         * settings form and its label travels in the translations on every
         * page. The credentials themselves are checked page-wide below.
         */
        $payment = ($this->start)();
        $ipn = paymentLogSignedIpn($payment->reference);

        $this->post(route('webhooks.payment', 'sslcommerz'), $ipn);

        $response = $this->actingAs($this->manager)
            ->get(route('admin.payments.show', $payment->public_id));

        $response->assertOk();

        /** @var array<int, array<string, mixed>> $logs */
        $logs = $response->viewData('page')['props']['logs'];

        expect($logs)->not->toBeEmpty();

        foreach ($logs as $entry) {
            // Encoded rather than walked, so a nested payload is covered too.
            $payload = (string) json_encode($entry['context']);

            foreach (PaymentLogRedactor::SECRET_KEYS as $secret) {
                expect($payload)->not->toContain($secret);
            }

            expect($payload)->not->toContain(PaymentLogRedactor::REDACTED);
        }

        // And the credentials themselves reach no part of the page.
        $response->assertDontSee($ipn['verify_sign'], escape: false);
        $response->assertDontSee(md5('pass'), escape: false);
    });

    it('keeps the diagnostic fields that make the trail worth having', function () {
        // Stripping everything would be safe and useless. What is left has to
        // be enough to reconcile a payment against a gateway's own records.
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $ipn = paymentLogShownIpn($this->manager, $payment);

        expect($ipn['context']['status'])->toBe('VALID')
            ->and($ipn['context']['val_id'])->toBe('val-1')
            ->and($ipn['context']['tran_id'])->toBe($payment->reference)
            ->and($ipn['direction'])->toBe(PaymentLog::INBOUND)
            ->and($ipn['ip_address'])->not->toBeNull();
    });

    it('says how many fields it withheld', function () {
        /*
         * A field that is simply missing reads as one the gateway never sent,
         * which is a different fact and a misleading one during an
         * investigation.
         */
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        // `verify_key` and `verify_sign` from the signed IPN.
        expect(paymentLogShownIpn($this->manager, $payment)['withheld'])->toBe(2);
    });

    it('still keeps the field name in the stored row', function () {
        /*
         * The other half of the contract, and the reason the two differ: "the
         * IPN carried no signature" and "the IPN's signature was stripped on the
         * way in" are different facts, and a stored log that cannot tell them
         * apart is no use in an investigation.
         */
        $payment = ($this->start)();

        $this->post(route('webhooks.payment', 'sslcommerz'), paymentLogSignedIpn($payment->reference));

        $stored = PaymentLog::query()
            ->where('payment_id', $payment->id)
            ->where('direction', PaymentLog::INBOUND)
            ->firstOrFail();

        expect($stored->context)->toHaveKey('verify_sign')
            ->and($stored->context['verify_sign'])->toBe(PaymentLogRedactor::REDACTED)
            ->and($stored->context['verify_sign'])->not->toBe(
                paymentLogSignedIpn($payment->reference)['verify_sign'],
            );
    });

    it('is closed to somebody without the payment permission', function () {
        $packageManager = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($packageManager)
            ->get(route('admin.payments.index'))
            ->assertForbidden();
    });

    it('is closed to an applicant entirely', function () {
        // §31.3: an account holder has their own invoices, not the platform's
        // payment log.
        $payment = ($this->start)();

        $this->actingAs($this->applicant)
            ->get(route('admin.payments.show', $payment->public_id))
            ->assertForbidden();
    });
});
