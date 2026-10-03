<?php

namespace App\Domain\Account\Queries;

use App\Domain\Account\Models\StaffContactVerification;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an identity's email and mobile verification stand, for the staff
 * account workspace: verified by the person, verified by staff (who and why),
 * or not yet verified.
 *
 * Staff-only. The internal reason is shown to the staff who act on the
 * account; it never reaches the person, who is only told which channel was
 * confirmed.
 */
class ContactVerificationState
{
    /**
     * @param  User|Supplier  $identity
     * @return array{email: array<string, mixed>, mobile: array<string, mixed>}
     */
    public function for(Model $identity): array
    {
        $type = $identity instanceof Supplier ? 'supplier' : 'user';

        $byStaff = StaffContactVerification::query()
            ->with('verifiedBy:id,name')
            ->where('identity_type', $type)
            ->where('identity_id', $identity->getKey())
            ->get()
            ->keyBy('channel');

        $channel = function (string $name, $verifiedAt) use ($byStaff): array {
            $staff = $byStaff->get($name);

            return [
                'verified' => $verifiedAt !== null,
                'verified_at' => $verifiedAt?->toIso8601String(),
                'by_staff' => $staff !== null,
                'verified_by' => $staff?->verifiedBy?->name,
                'reason' => $staff?->reason,
            ];
        };

        return [
            'email' => $channel('email', $identity->email_verified_at),
            'mobile' => $channel('mobile', $identity->mobile_verified_at),
        ];
    }
}
