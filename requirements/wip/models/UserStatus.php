<?php

namespace App\Domain\Account\Enums;

use App\Domain\Account\Models\BusinessAccount;
use App\Support\StateMachine\TransitionableState;

/**
 * The status of a **login identity** — a person, not a business (§6).
 *
 * Deliberately separate from {@see AccountStatus}, which governs a
 * {@see BusinessAccount}. Conflating them is what
 * made a Feriwala staff member need commercial KYC and an activation payment
 * before they could open the admin panel, and would have made an invited staff
 * member need their own KYC to join someone else's workspace.
 *
 * The division of labour:
 *
 *   - **This enum** answers "may this human use the platform at all?" It is a
 *     security and access question: sign-in, lockout, suspension, closure. It
 *     gates *everything*, administration included.
 *   - **{@see AccountStatus}** answers "may this business trade?" It gates
 *     dropshipping, wholesale, wallet, orders, websites — the commercial ERP —
 *     and nothing about administration.
 *
 * Small on purpose. §6 asks for lock, unlock, suspension and closure; anything
 * richer belongs to the business account's much larger lifecycle.
 */
enum UserStatus: string implements TransitionableState
{
    /** Normal. May sign in, and may use whatever their roles and account allow. */
    case Active = 'active';

    /**
     * Locked by a security control rather than a person — failed login attempts,
     * a suspicious sign-in, a password reset in progress (§6).
     *
     * Reversible without an administrator having judged anything about the
     * person, which is why it is not Suspended.
     */
    case Locked = 'locked';

    /** Suspended by an administrator. No access anywhere, including admin. */
    case Suspended = 'suspended';

    /** Closed. Terminal; retention rules take over from here (D18). */
    case Closed = 'closed';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Active => [self::Locked, self::Suspended, self::Closed],

            // Unlocking returns to Active; a locked account can still be
            // suspended or closed while locked.
            self::Locked => [self::Active, self::Suspended, self::Closed],

            // Suspension is reversible — closure is what is not (D18).
            self::Suspended => [self::Active, self::Closed],

            self::Closed => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Locked => 'Locked',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Locked => 'warning',
            self::Suspended, self::Closed => 'danger',
        };
    }

    /**
     * Whether this identity may reach the platform at all.
     *
     * The single question the identity gate asks, on every authenticated
     * request. A false here beats every permission and every business account
     * status — there is no route, admin included, that outranks it.
     */
    public function permitsAccess(): bool
    {
        return $this === self::Active;
    }
}
