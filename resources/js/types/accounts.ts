import type { StatusTone } from '@/lib/status';

/**
 * One row of the staff account directory.
 *
 * A directory shape, deliberately thin: no KYC document, payout detail,
 * wallet figure or secret appears here. Those belong to the dossier, behind
 * their own abilities.
 */
export type AccountDirectoryRow = {
    /** The account's public id (ULID) — never a database id. */
    id: string;
    name: string;
    owner: string | null;
    email: string | null;
    mobile: string | null;
    email_verified: boolean;
    mobile_verified: boolean;
    status_label: string;
    status_tone: StatusTone;
    package: string | null;
    package_status: string | null;
    registered_at: string | null;
    activated_at: string | null;
};
