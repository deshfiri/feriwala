<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Account\Actions\VerifyMobile;
use App\Domain\Account\VerificationCodes;
use App\Domain\Supplier\Models\Supplier;
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
        protected AdvanceSupplierPastVerification $advance,
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

            $this->advance->handle($supplier);
        });

        return true;
    }
}
