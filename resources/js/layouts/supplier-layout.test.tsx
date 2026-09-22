// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const english = {
    supplier: {
        portal_name: 'Supplier portal',
        nav: {
            dashboard: 'Dashboard',
            application: 'Application & KYC',
            listings: 'Product listings',
            products: 'Approved products',
            rates: 'Rates',
            stock: 'Stock & availability',
            allocations: 'Allocated orders',
            payables: 'Payables',
            notifications: 'Notifications',
            profile: 'Profile',
            security: 'Security',
            sign_out: 'Sign out',
            skip: 'Skip to content',
        },
    },
};

const bangla = {
    supplier: {
        portal_name: 'সাপ্লায়ার পোর্টাল',
        nav: {
            dashboard: 'ড্যাশবোর্ড',
            application: 'আবেদন ও কেওয়াইসি',
            listings: 'পণ্য তালিকাভুক্তি',
            products: 'অনুমোদিত পণ্য',
            rates: 'রেট',
            stock: 'মজুত ও প্রাপ্যতা',
            allocations: 'বরাদ্দকৃত অর্ডার',
            payables: 'পাওনা',
            notifications: 'বিজ্ঞপ্তি',
            profile: 'প্রোফাইল',
            security: 'নিরাপত্তা',
            sign_out: 'সাইন আউট',
            skip: 'মূল অংশে যান',
        },
    },
};

const page = {
    props: {
        translations: english as unknown,
        supplierAccount: null as unknown,
    },
};

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: unknown;
        }) =>
            createElement(
                'a',
                { href: typeof href === 'string' ? href : href.url, ...rest },
                children as never,
            ),
        router: { post: vi.fn() },
        usePage: () => page,
    };
});

vi.mock('@/components/branding-head', () => ({ default: () => null }));
vi.mock('@/components/language-switcher', () => ({ default: () => null }));
vi.mock('@/components/appearance-tabs', () => ({ default: () => null }));

const { default: SupplierLayout } = await import('./supplier-layout');

const account = (overrides: Record<string, unknown> = {}) => ({
    business_name: 'Zulu Traders Ltd',
    reference: 'SUP-260921-ABCD',
    status: 'kyc_pending',
    status_label: 'KYC pending',
    operational: false,
    unread_notifications: 0,
    ...overrides,
});

const OPERATIONAL_ONLY = [
    'Product listings',
    'Approved products',
    'Rates',
    'Stock & availability',
    'Allocated orders',
    'Payables',
];

afterEach(() => {
    page.props.translations = english;
    page.props.supplierAccount = null;
});

/**
 * The Supplier portal shell (D25): its own header and navigation, separate from
 * the Client/Partner ERP sidebar. Hiding a link is only a convenience — the
 * server refuses the routes regardless — but the shell must not offer doors
 * that open onto a refusal.
 */
describe('the supplier portal navigation', () => {
    it('gives an applicant the application and account pages and no operational door', () => {
        page.props.supplierAccount = account();

        render(<SupplierLayout>content</SupplierLayout>);

        for (const name of [
            'Dashboard',
            'Application & KYC',
            'Notifications',
            'Profile',
            'Security',
        ]) {
            expect(screen.getByRole('link', { name })).toBeInTheDocument();
        }

        for (const name of OPERATIONAL_ONLY) {
            expect(
                screen.queryByRole('link', { name }),
            ).not.toBeInTheDocument();
        }
    });

    it('opens listings, products, rates and stock once the supplier is approved', () => {
        page.props.supplierAccount = account({
            status: 'approved',
            status_label: 'Approved',
            operational: true,
        });

        render(<SupplierLayout>content</SupplierLayout>);

        for (const name of OPERATIONAL_ONLY) {
            expect(screen.getByRole('link', { name })).toBeInTheDocument();
        }
    });

    it('shows whose portal this is, and how many notifications are unread', () => {
        page.props.supplierAccount = account({ unread_notifications: 3 });

        render(<SupplierLayout>content</SupplierLayout>);

        expect(
            screen.getByText('Zulu Traders Ltd · SUP-260921-ABCD'),
        ).toBeInTheDocument();
        expect(screen.getByText('KYC pending')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('link', { name: /Notifications/ }),
            ).getByText('3'),
        ).toBeInTheDocument();
    });

    it('renders every label in Bangla and none in English', () => {
        page.props.translations = bangla;
        page.props.supplierAccount = account({ operational: true });

        render(<SupplierLayout>content</SupplierLayout>);

        for (const name of [
            'ড্যাশবোর্ড',
            'আবেদন ও কেওয়াইসি',
            'পণ্য তালিকাভুক্তি',
            'রেট',
            'মজুত ও প্রাপ্যতা',
            'বরাদ্দকৃত অর্ডার',
            'পাওনা',
            'বিজ্ঞপ্তি',
        ]) {
            expect(screen.getByRole('link', { name })).toBeInTheDocument();
        }

        expect(
            screen.queryByRole('link', { name: 'Dashboard' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('সাপ্লায়ার পোর্টাল')).toBeInTheDocument();
    });

    it('keeps a long navigation inside its own scroller rather than widening the page', () => {
        page.props.supplierAccount = account({ operational: true });

        render(<SupplierLayout>content</SupplierLayout>);

        const nav = screen.getByRole('navigation', {
            name: 'Supplier portal',
        });

        // The strip scrolls sideways on a phone; the page itself never does.
        expect(nav.className).toContain('overflow-x-auto');
        expect(nav.querySelector('ul')?.className).toContain('min-w-max');
    });

    it('offers a skip link to the main content', () => {
        page.props.supplierAccount = account();

        render(<SupplierLayout>content</SupplierLayout>);

        expect(
            screen.getByRole('link', { name: 'Skip to content' }),
        ).toHaveAttribute('href', '#supplier-main');
        expect(screen.getByRole('main')).toHaveAttribute('id', 'supplier-main');
    });
});
