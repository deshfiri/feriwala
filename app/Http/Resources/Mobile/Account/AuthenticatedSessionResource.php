<?php

namespace App\Http\Resources\Mobile\Account;

use App\Domain\Account\Models\AuthenticatedSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One browser sign-in of the person's, live or finished.
 *
 * The session identifier is never exposed; the device and address identify it.
 *
 * @mixin AuthenticatedSession
 */
class AuthenticatedSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            'device' => $this->device_label,
            'ip_address' => $this->ip_address,
            'last_active_at' => $this->last_active_at->toIso8601String(),
            'signed_in_at' => $this->created_at?->toIso8601String(),
            'ended' => $this->hasEnded(),
            /** `signed_out`, `revoked` or `password_changed`; null while live. */
            'ended_reason' => $this->ended_reason,
        ];
    }
}
