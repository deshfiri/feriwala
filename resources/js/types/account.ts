/**
 * The one business account the signed-in person works in (D1).
 *
 * Singular, and there is no list beside it. The starter kit shared `currentTeam`
 * plus `teams` so the shell could offer a switcher; a person here belongs to one
 * account and cannot switch, so shipping a list would only invite a control that
 * has nothing to control.
 *
 * `managesStaff` and `allowsStaff` are separate answers to separate questions:
 * whether this person may manage staff, and whether the package has any staff
 * facility at all. Either being false hides the Staff area outright rather than
 * showing it disabled.
 */
export interface AccountContext {
    id: string;
    name: string;
    status: string;
    role: 'owner' | 'manager' | 'staff';
    roleLabel: string;
    isOwner: boolean;
    managesStaff: boolean;
    allowsStaff: boolean;
}

export interface StaffMember {
    id: string;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    isOwner: boolean;
    isYou: boolean;
    canManage: boolean;
    joinedAt: string | null;
}

export interface StaffInvitation {
    id: string;
    email: string;
    role: string;
    roleLabel: string;
    invitedBy: string | null;
    expiresAt: string;
}

export interface StaffAllowance {
    /** null means the package sets no cap. */
    limit: number | null;
    used: number;
    remaining: number | null;
}
