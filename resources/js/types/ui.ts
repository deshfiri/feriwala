import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * One choice in a picker.
 *
 * Values come from the server — a package slug, a country code — because a
 * client-side list is one that drifts from what the server will accept.
 */
export type SelectOption = {
    value: string;
    label: string;
};

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
