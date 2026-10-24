<?php

use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;

/*
 * The transaction types and what each one knows (P2-6, §23.1).
 *
 * Nothing that posts money should have to remember which way a courier charge
 * goes. The type says.
 */

it('knows the direction of every trading type', function () {
    // Twenty-two of the twenty-six are one-directional by nature, and so is
    // each half of a balance conversion.
    $directed = collect(LedgerTransactionType::cases())
        ->reject(fn (LedgerTransactionType $type) => $type->isCorrection());

    expect($directed)->toHaveCount(24);

    foreach ($directed as $type) {
        expect($type->direction())->not->toBeNull();
    }
});

it('puts every named credit on the credit side', function () {
    foreach ([
        LedgerTransactionType::DepositCredit,
        LedgerTransactionType::TopUpCredit,
        LedgerTransactionType::SalesCredit,
        LedgerTransactionType::CommissionCredit,
        LedgerTransactionType::ReferralRewardCredit,
        LedgerTransactionType::JoiningRewardCredit,
        LedgerTransactionType::CodCollectionCredit,
        LedgerTransactionType::PromotionalCredit,
    ] as $type) {
        expect($type->direction())->toBe(LedgerDirection::Credit);
    }
});

it('puts every named charge on the debit side', function () {
    foreach ([
        LedgerTransactionType::RegistrationFeeDebit,
        LedgerTransactionType::PackageFeeDebit,
        LedgerTransactionType::CourierChargeDebit,
        LedgerTransactionType::WithdrawalDebit,
        LedgerTransactionType::RefundDebit,
        LedgerTransactionType::PlatformFeeDebit,
    ] as $type) {
        expect($type->direction())->toBe(LedgerDirection::Debit);
    }
});

it('lets a correction go either way', function () {
    /*
     * An adjustment that could only ever add would be no use for putting right
     * an overpayment, and a return adjustment goes whichever way the return
     * went. The caller says, and the posting service makes them.
     */
    foreach ([
        LedgerTransactionType::ManualAdjustment,
        LedgerTransactionType::ReturnAdjustment,
        LedgerTransactionType::CommissionReversal,
        LedgerTransactionType::ReferralRewardReversal,
    ] as $type) {
        expect($type->direction())->toBeNull()
            ->and($type->isCorrection())->toBeTrue();
    }
});

it('makes every correction carry a reason', function () {
    // A figure that changed for no recorded reason is the first thing an
    // auditor asks about. Turning platform credit into withdrawable money is a
    // decision of the same weight, so it says why as well.
    foreach (LedgerTransactionType::cases() as $type) {
        expect($type->requiresReason())->toBe($type->isCorrection() || $type->isConversion());
    }
});

it('maps what a customer pays in and what is rewarded to platform credit, and what is earned to withdrawable', function () {
    foreach ([
        LedgerTransactionType::DepositCredit,
        LedgerTransactionType::TopUpCredit,
        LedgerTransactionType::ReferralRewardCredit,
        LedgerTransactionType::JoiningRewardCredit,
        LedgerTransactionType::PromotionalCredit,
    ] as $type) {
        expect($type->creditBalanceType())->toBe(BalanceType::PlatformCredit);
    }

    foreach ([
        LedgerTransactionType::SalesCredit,
        LedgerTransactionType::CommissionCredit,
        LedgerTransactionType::CodCollectionCredit,
    ] as $type) {
        expect($type->creditBalanceType())->toBe(BalanceType::Withdrawable);
    }
});

it('never defaults the kind of money for a credit a person decides', function () {
    foreach ([
        LedgerTransactionType::ManualAdjustment,
        LedgerTransactionType::ReturnAdjustment,
        LedgerTransactionType::CommissionReversal,
        LedgerTransactionType::ReferralRewardReversal,
    ] as $type) {
        expect($type->creditBalanceType())->toBeNull();
    }
});

it('requires a person behind a manual adjustment and nothing else', function () {
    // Somebody deciding to move money by hand is a different act from the
    // system recording what happened.
    expect(LedgerTransactionType::ManualAdjustment->requiresActor())->toBeTrue()
        ->and(LedgerTransactionType::TopUpCredit->requiresActor())->toBeFalse()
        ->and(LedgerTransactionType::CommissionReversal->requiresActor())->toBeFalse();
});
