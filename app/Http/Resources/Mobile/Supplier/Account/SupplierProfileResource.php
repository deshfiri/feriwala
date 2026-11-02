<?php

namespace App\Http\Resources\Mobile\Supplier\Account;

use App\Domain\Supplier\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Supplier's own profile, as the web profile page shows it.
 *
 * Email and mobile are shown but not editable: changing a verified identifier
 * needs its own re-verification flow. Never the reviewer's note or payout detail.
 *
 * @mixin Supplier
 */
class SupplierProfileResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Human reference, e.g. quoted to support. */
            'reference' => $this->reference,
            'business_name' => $this->business_name,
            'contact_person_name' => $this->contact_person_name,
            'business_address' => $this->business_address,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'trade_licence_number' => $this->trade_licence_number,
            'tax_identification_number' => $this->tax_identification_number,
            /** Preferred language, `en` or `bn`. */
            'locale' => $this->locale,
        ];
    }
}