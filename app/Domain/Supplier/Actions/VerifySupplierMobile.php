<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\VerificationCodes;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;

/**
 * Confirms a Supplier's mobile number against a submitted code (D25).
 *
 * Mirrors {@see VerifyMobile} in shape without
 * sharing code: verification is a fact about the number, kept separate from
 * whether the Supplier's application is now ready to move on to KYC.
 */
class VerifySupplierMobile
{
    public function __construct(
        protected VerificationCodes $codes,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether the code was correct
     */
    public function handle(Supplier $supplier, string $submittedCode): bool
    {
        $mobile = (string) $supplier->mobile;

        if (! $this->codes->verify(SendSupplierMobileVerificationCode::PURPOSE, $mobile, $submittedCode)) {
            return false;
        }

        $this->database->transaction(function () use ($supplier) {
            $supplier->forceFill(['mobile_verified_at' => now()])->save();

            $this->advanceIfFullyVerified($supplier);
        });

        return true;
    }

    protected function advanceIfFullyVerified(Supplier $supplier): void
    {
        if ($supplier->status !== SupplierStatus::VerificationPending) {
            return;
        }

        if (! $supplier->isVerified()) {
            return;
        }

        $supplier->transitionWithHistory(
            SupplierStatus::KycPending,
            new StatusChange(reason: 'Email and mobile verified.'),
            ['source' => SupplierStatusChangeSource::Supplier],
        );
    }
}
