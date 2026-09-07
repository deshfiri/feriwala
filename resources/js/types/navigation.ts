import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

/**
 * The tones a navigation badge may carry.
 *
 * Deliberately a small closed set rather than a free colour. A badge that can be
 * any colour becomes a second status system competing with `StatusPill`, and
 * §33.9 already settled that colour never carries meaning on its own — every
 * badge here renders its label as text.
 */
export type NavBadgeTone = 'brand' | 'info' | 'danger' | 'neutral';

export type NavBadge = {
    label: string;
    tone?: NavBadgeTone;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: NavBadge;

    /**
     * Child entries. A parent that has them expands to reveal them; its own
     * `href` is still required and still navigates, so the group is never a
     * dead end for anyone driving the sidebar from the keyboard.
     */
    items?: NavItem[];
};

/**
 * A labelled block of navigation, matching the sectioned sidebar (§33.2).
 *
 * The label is what makes a long sidebar scannable — someone hunting for the KYC
 * queue looks for "Administration" first and reads four entries, rather than
 * reading twenty.
 */
export type NavGroup = {
    /** Rendered as the uppercase section heading. */
    label: string;
    items: NavItem[];
};
