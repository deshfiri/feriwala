<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Moves a Supplier from verification on to KYC once what verification
 * currently asks of them is done.
 *
 * Email is always asked for. Mobile is asked for only while the administrator's
 * mobile-verification requirement is on -- the same switch a Client/Partner
 * owner answers to -- so with it off a Supplier is never held on a step they
 * cannot complete.
 *
 * Called from every place a verification can complete (email link, mobile
 * code) and from the verification screens themselves, so a Supplier who was
 * waiting when the switch was turned off is caught the next time they look.
 * A no-op for anyone not sitting at {@see SupplierStatus::VerificationPending}.
 */
class AdvanceSupplierPastVerification
{
    public function __construct(
        protected MobileVerificationRequirement $requirement,
        protected DatabaseManager $database,
    ) {}

    /**
     * Whether this Supplier has done everything verification currently needs.
     */
    public function isComplete(Supplier $supplier): bool
    {
        if ($supplier->email_verified_at === null) {
            return false;
        }

        return ! $this->requirement->isRequired() || $supplier->mobile_verified_at !== null;
    }

    public function handle(Supplier $supplier): void
    {
        if ($supplier->status !== SupplierStatus::VerificationPending || ! $this->isComplete($supplier)) {
            return;
        }

        $this->database->transaction(function () use ($supplier) {
            /** @var Supplier|null $locked */
            $locked = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SupplierStatus::VerificationPending) {
                return;
            }

            $locked->transitionWithHistory(
                SupplierStatus::KycPending,
                new StatusChange(reason: $this->requirement->isRequired()
                    ? 'Email and mobile verified.'
                    : 'Email verified; mobile verification is switched off.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            $supplier->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }
}
