<?php

namespace App\Http\Resources\Mobile\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in person, as the mobile app needs to know them.
 *
 * Identity and the state of their business only. Nothing about the wallet,
 * ledger or KYC leaves here; those have their own endpoints and their own
 * authorisation.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->businessAccount;

        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            /** Preferred language, `en` or `bn`. */
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'mobile_verified' => $this->mobile_verified_at !== null,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            /** @var string Whether this login may use the platform: `active`, `suspended`, … */
            'identity_status' => $this->identity_status->value,
            /** @var list<string> Platform (staff) roles held by this login; empty for a business user. */
            'platform_roles' => $this->roles->pluck('name')->values()->all(),
            /** @var array{id: string, name: string, status: string, is_activated: bool}|null The person's business account; null for platform staff and invitees. */
            'business_account' => $account === null ? null : [
                'id' => $account->public_id,
                'name' => $account->name,
                'status' => $account->status->value,
                'is_activated' => $account->isActivated(),
            ],
        ];
    }
}
