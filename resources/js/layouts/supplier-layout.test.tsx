// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';

// `useIsMobile` (behind the shared Sidebar this layout now renders) reads
// `window.matchMedia` once at module load time, not per render -- this has
// to be in place before `./supplier-layout` is ever imported below, not in
// a `beforeEach`.
window.matchMedia = vi.fn().mockReturnValue({
    matches: false,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
});

const english = {
    common: { nav: { skip: 'Skip to content' } },
    supplier: {
        portal_name: 'Supplier portal',
        nav_groups: {
            overview: 'Overview',
            operations: 'Operations',
            finance: 'Finance',
            account: 'Account',
        },
        nav: {
            dashboard: 'Dashboard',
            application: 'Application & KYC',
            listings: 'Product listings',
            products: 'Approved products',
            rates: 'Rates',
            stock: 'Stock & availability',
            allocations: 'Allocated orders',
            payables: 'Payables',
            wallet: 'Wallet',
            payout_methods: 'Payout methods',
            withdrawals: 'Withdrawals',
            notifications: 'Notifications',
            profile: 'Profile',
            security: 'Security',
            sign_out: 'Sign out',
            skip: 'Skip to content',
        },
    },
};

const bangla = {
    common: { nav: { skip: 'মূল অংশে যান' } },
    supplier: {
        portal_name: 'সাপ্লায়ার পোর্টাল',
        nav_groups: {
            overview: 'সারসংক্ষেপ',
            operations: 'কার্যক্রম',
            finance: 'আর্থিক',
            account: 'অ্যাকাউন্ট',
        },
        nav: {
            dashboard: 'ড্যাশবোর্ড',
            application: 'আবেদন ও কেওয়াইসি',
            listings: 'পণ্য তালিকাভুক্তি',
            products: 'অনুমোদিত পণ্য',
            rates: 'রেট',
            stock: 'মজুত ও প্রাপ্যতা',
            allocations: 'বরাদ্দকৃত অর্ডার',
            payables: 'পাওনা',
            wallet: 'ওয়ালেট',
            payout_methods: 'পরিশোধ পদ্ধতি',
            withdrawals: 'উত্তোলন',
            notifications: 'বিজ্ঞপ্তি',
            profile: 'প্রোফাইল',
            security: 'নিরাপত্তা',
            sign_out: 'সাইন আউট',
            skip: 'মূল অংশে যান',
        },
    },
};

const page = {
    url: '/supplier/dashboard',
    props: {
        translations: english as unknown,
        supplierAccount: null as unknown,
        sidebarOpen: true,
        name: 'Feriwala',
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
vi.mock('@/components/app-logo-icon', () => ({ default: () => null }));

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

/**
 * `NavMain`'s collapsed-sidebar tooltips are real Radix `Tooltip`s, which
 * throw without a `TooltipProvider` ancestor — the same wrapper `app.tsx`
 * already provides around the whole app in production.
 */
function renderLayout(children: ReactNode = 'content') {
    return render(
        <TooltipProvider>
            <SupplierLayout>{children}</SupplierLayout>
        </TooltipProvider>,
    );
}

const OPERATIONAL_ONLY = [
    'Product listings',
    'Approved products',
    'Stock & availability',
    'Allocated orders',
    'Payables',
    'Wallet',
    'Payout methods',
    'Withdrawals',
];

afterEach(() => {
    page.url = '/supplier/dashboard';
    page.props.translations = english;
    page.props.supplierAccount = null;
});

/**
 * The Supplier portal shell (D25): its own sidebar and navigation, built on
 * the same shared `Sidebar`/`NavMain` primitives the Admin/Client ERP uses,
 * but reading from the Supplier's own guard session, not
 * `useNavigation()`'s permission-scoped registry. Hiding a link is only a
 * convenience — the server refuses the routes regardless — but the shell
 * must not offer doors that open onto a refusal.
 */
describe('the supplier portal navigation', () => {
    it('gives an applicant the application and account pages and no operational door', () => {
        page.props.supplierAccount = account();

        renderLayout();

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

    it('opens listings, products and stock once the supplier is approved', () => {
        page.props.supplierAccount = account({
            status: 'approved',
            status_label: 'Approved',
            operational: true,
        });

        renderLayout();

        for (const name of OPERATIONAL_ONLY) {
            expect(screen.getByRole('link', { name })).toBeInTheDocument();
        }
    });

    it('groups navigation into Overview, Operations, Finance and Account', () => {
        page.props.supplierAccount = account({
            status: 'approved',
            status_label: 'Approved',
            operational: true,
        });

        renderLayout();

        for (const label of ['Overview', 'Operations', 'Finance', 'Account']) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
    });

    it('shows whose portal this is, and how many notifications are unread', () => {
        page.props.supplierAccount = account({ unread_notifications: 3 });

        renderLayout();

        expect(screen.getByText('Zulu Traders Ltd')).toBeInTheDocument();
        expect(screen.getByText('SUP-260921-ABCD')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('link', { name: /Notifications/ }),
            ).getByText('3'),
        ).toBeInTheDocument();
    });

    it('renders every label in Bangla and none in English', () => {
        page.props.translations = bangla;
        page.props.supplierAccount = account({ operational: true });

        renderLayout();

        for (const name of [
            'ড্যাশবোর্ড',
            'আবেদন ও কেওয়াইসি',
            'পণ্য তালিকাভুক্তি',
            'অনুমোদিত পণ্য',
            'মজুত ও প্রাপ্যতা',
            'বরাদ্দকৃত অর্ডার',
            'পাওনা',
            'ওয়ালেট',
            'পরিশোধ পদ্ধতি',
            'উত্তোলন',
            'বিজ্ঞপ্তি',
        ]) {
            expect(screen.getByRole('link', { name })).toBeInTheDocument();
        }

        expect(
            screen.queryByRole('link', { name: 'Dashboard' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('সারসংক্ষেপ')).toBeInTheDocument();
    });

    it('offers a skip link to the main content', () => {
        page.props.supplierAccount = account();

        renderLayout();

        expect(
            screen.getByRole('link', { name: 'Skip to content' }),
        ).toHaveAttribute('href', '#supplier-main');
        expect(screen.getByRole('main')).toHaveAttribute('id', 'supplier-main');
    });

    /*
     * Reads the current path from Inertia's own shared page.url, not
     * `window.location` — the latter is empty during SSR and only real once
     * hydrated, which made React log a hydration mismatch on every load of
     * this page (caught in browser verification, not by an earlier version
     * of this test suite, which never asserted on the active state at all).
     * The shared `NavMain` marks the active row both visually
     * (`data-active`, which `SidebarMenuButton` styles from) and for
     * assistive tech (`aria-current="page"`, the same on every portal's
     * sidebar since they all render through `NavMain`).
     */
    it('marks the current page active from the page URL, not window.location', () => {
        page.props.supplierAccount = account();
        page.url = '/supplier/dashboard';

        renderLayout();

        const active = screen.getByRole('link', { name: 'Dashboard' });
        const inactive = screen.getByRole('link', { name: 'Profile' });

        expect(active).toHaveAttribute('data-active', 'true');
        expect(active).toHaveAttribute('aria-current', 'page');
        expect(inactive).toHaveAttribute('data-active', 'false');
        expect(inactive).not.toHaveAttribute('aria-current');
    });
});
