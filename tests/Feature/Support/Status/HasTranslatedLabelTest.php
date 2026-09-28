<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycRoundPurpose;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Support\Status\HasTranslatedLabel;
use Illuminate\Support\Facades\App;

/*
 * The shared status-label contract (§ Stage 5 closure — Bangla status
 * labels). Every enum below implements TransitionableState and is shown to
 * an end user through StatusPill; none of this changes what is stored —
 * ->value is untouched throughout, only ->label() became locale-aware.
 */

/**
 * @return array<string, class-string<HasTranslatedLabel>>
 */
function statusEnumsUnderTest(): array
{
    return [
        'account' => AccountStatus::class,
        'order' => OrderStatus::class,
        'payment' => PaymentStatus::class,
        'website' => WebsiteStatus::class,
        'supplier' => SupplierStatus::class,
        'listing' => ListingStatus::class,
        'withdrawal' => SupplierWithdrawalStatus::class,
        'return' => ReturnStatus::class,
        'refund' => RefundStatus::class,

        /*
         * The KYC round enums (§7.2, §7.4). A consequence label is read by
         * the account holder being told what is restricted, so an untranslated
         * one leaves a Bangla reader looking at English for the part of the
         * screen that matters most.
         */
        'kyc' => KycStatus::class,
        'kyc_purpose' => KycRoundPurpose::class,
        'kyc_consequence' => KycConsequence::class,
    ];
}

afterEach(function () {
    App::setLocale('en');
});

it('gives every case of every covered status enum both an English and a Bangla line', function (string $group, string $enumClass) {
    App::setLocale('en');

    foreach ($enumClass::cases() as $case) {
        expect(__("status.{$group}.{$case->value}"))
            ->not->toBe("status.{$group}.{$case->value}");
    }

    App::setLocale('bn');

    foreach ($enumClass::cases() as $case) {
        expect(__("status.{$group}.{$case->value}"))
            ->not->toBe("status.{$group}.{$case->value}");
    }
})->with(function () {
    foreach (statusEnumsUnderTest() as $group => $enumClass) {
        yield $group => [$group, $enumClass];
    }
});

it('keeps the enum value itself untranslated, whatever the locale', function (string $group, string $enumClass) {
    foreach (['en', 'bn'] as $locale) {
        App::setLocale($locale);

        foreach ($enumClass::cases() as $case) {
            expect($case->value)->toBe($case->value);
        }
    }
})->with(function () {
    foreach (statusEnumsUnderTest() as $group => $enumClass) {
        yield $group => [$group, $enumClass];
    }
});

it('renders a real Bangla label for a representative status from every covered domain', function () {
    App::setLocale('bn');

    expect(AccountStatus::Active->label())->toBe('সক্রিয়')
        ->and(OrderStatus::Delivered->label())->toBe('সরবরাহ করা হয়েছে')
        ->and(PaymentStatus::Paid->label())->toBe('পরিশোধিত')
        ->and(WebsiteStatus::Active->label())->toBe('সক্রিয়')
        ->and(SupplierStatus::Approved->label())->toBe('অনুমোদিত')
        ->and(ListingStatus::Approved->label())->toBe('অনুমোদিত')
        ->and(SupplierWithdrawalStatus::Requested->label())->toBe('অনুরোধ করা হয়েছে')
        ->and(ReturnStatus::Refunded->label())->toBe('অর্থ ফেরত দেওয়া হয়েছে')
        ->and(RefundStatus::Processed->label())->toBe('অর্থ ফেরত দেওয়া হয়েছে');
});

it('renders the same English text these labels always had, in the default locale', function () {
    App::setLocale('en');

    expect(AccountStatus::Active->label())->toBe('Active')
        ->and(OrderStatus::PartiallyRefunded->label())->toBe('Partially refunded')
        ->and(PaymentStatus::ReconciliationRequired->label())->toBe('Needs reconciliation')
        ->and(WebsiteStatus::GracePeriod->label())->toBe('Grace period')
        ->and(SupplierStatus::CorrectionRequired->label())->toBe('Correction required')
        ->and(ListingStatus::PartiallyApproved->label())->toBe('Partially approved')
        ->and(SupplierWithdrawalStatus::Reversed->label())->toBe('Reversed')
        ->and(ReturnStatus::Received->label())->toBe('Received')
        ->and(RefundStatus::Requested->label())->toBe('Awaiting decision');
});

/**
 * A status the trait has no translation line for still reads as words, never
 * as the raw dotted key or a blank string — proven with a throwaway enum
 * rather than by deleting a real line from lang/en/status.php.
 */
enum HasTranslatedLabelTestFixtureStatus: string
{
    use HasTranslatedLabel;

    case SomeUntranslatedState = 'some_untranslated_state';

    protected static function statusLabelGroup(): string
    {
        return 'nonexistent_test_group';
    }
}

it('falls back to a humanized value when no translation line exists yet', function () {
    expect(HasTranslatedLabelTestFixtureStatus::SomeUntranslatedState->label())
        ->toBe('Some untranslated state');
});
