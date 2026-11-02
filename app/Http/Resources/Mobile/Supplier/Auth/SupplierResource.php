<?php

namespace App\Http\Resources\Mobile\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in Supplier, as the mobile app needs to know them.
 *
 * Identity and where they stand in the approval funnel (D25). Never the
 * reviewer's `review_note`, which is written about the supplier, nor payout
 * details.
 *
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            /** Human reference, e.g. quoted to support. */
            'reference' => $this->reference,
            'business_name' => $this->business_name,
            'contact_person_name' => $this->contact_person_name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            /** Preferred language, `en` or `bn`. */
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'mobile_verified' => $this->mobile_verified_at !== null,
            /** Where the supplier is in the approval funnel (D25). */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            /** Only an approved, un-suspended supplier may use listings, offers, stock and withdrawals. */
            'is_operational' => $this->isOperational(),
        ];
    }
}
