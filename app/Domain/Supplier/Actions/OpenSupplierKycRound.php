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
 *
 * An editable round is given what it asks for ({@see CaptureSupplierKycRequirements})
 * the first time it is opened, including a draft that pre-dates requirements.
 * A round already under review is left as it is.
 */
class OpenSupplierKycRound
{
    public function __construct(
        protected CaptureSupplierKycRequirements $capture,
    ) {}

    public function handle(Supplier $supplier): SupplierKycSubmission
    {
        $round = $supplier->kycSubmissions()->first()
            ?? $supplier->kycSubmissions()->create([
                'round' => 1,
                'status' => SupplierKycStatus::Draft,
            ]);

        if ($round->status->isEditable()) {
            $this->capture->handle($round);
        }

        return $round;
    }
}
