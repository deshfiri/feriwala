<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycSubmission;

/**
 * The Supplier's KYC round — the one their own screens work against (D25,
 * P13-7).
 *
 * A Supplier has exactly one round in this beta. The first is opened on
 * demand as a {@see SupplierKycStatus::Draft}; from then on the same round is
 * returned whatever state it is in, so a submitted, decided or under-review
 * round is shown read-only rather than quietly replaced by a fresh draft the
 * Supplier could keep adding documents to. A correction reopens the *same*
 * round ({@see SupplierKycStatus::CorrectionRequired} → submitted), and a
 * rejection is final until staff decides otherwise — there is no Supplier-side
 * "start again".
 */
class OpenSupplierKycRound
{
    public function handle(Supplier $supplier): SupplierKycSubmission
    {
        return $supplier->kycSubmissions()->first()
            ?? $supplier->kycSubmissions()->create([
                'round' => 1,
                'status' => SupplierKycStatus::Draft,
            ]);
    }
}
