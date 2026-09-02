import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Circle,
    Clock,
    RotateCcw,
    Truck,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

/**
 * The five tones every status in the system maps to.
 *
 * Feriwala has very large status sets — 22 for accounts, 28 for orders plus
 * admin-defined ones, 14 for websites, and more. Mapping them all onto five
 * tones keeps the interface legible: an operator learns one colour vocabulary
 * instead of relearning it per module.
 */
export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Colour is never the only carrier of meaning (§33.9). Every tone has a default
 * icon so a status still reads for a colour-blind user, in greyscale print, or
 * at a glance in a dense table.
 */
export const statusIcons: Record<StatusTone, LucideIcon> = {
    success: CheckCircle2,
    warning: Clock,
    danger: XCircle,
    info: Truck,
    neutral: Circle,
};

/**
 * Additional icons for statuses whose meaning the tone alone does not carry.
 */
export const statusIconOverrides = {
    reversed: RotateCcw,
    blocked: Ban,
    attention: AlertTriangle,
} satisfies Record<string, LucideIcon>;

export const statusToneClasses: Record<StatusTone, string> = {
    success: 'bg-success-subtle text-success',
    warning: 'bg-warning-subtle text-warning',
    danger: 'bg-danger-subtle text-danger',
    info: 'bg-info-subtle text-info',
    neutral: 'bg-neutral-status-subtle text-neutral-status',
};
