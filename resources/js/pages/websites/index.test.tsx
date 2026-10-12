// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type {
    WebsiteAllocationCard,
    WebsiteOwnerSummary,
} from '@/types/website';

const websitesPage = vi.hoisted(() => ({
    props: { translations: {} } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
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
        usePage: () => websitesPage,
    };
});

const { default: Websites } = await import('./index');

function summary(
    overrides: Partial<WebsiteOwnerSummary> = {},
): WebsiteOwnerSummary {
    return {
        domain_record: null,
        hosting_record: null,
        id: '01WEBSITE',
        name: 'Nasrin Fashion',
        host: 'nasrin.feriwala.shop',
        status: 'active',
        status_label: 'Active',
        is_live: true,
        connection_health: 'healthy',
        last_synced_at: '2026-09-16T10:00:00+06:00',
        outstanding_charges: 0,
        account: null,
        created_at: '2026-09-16T10:00:00+06:00',
        ...overrides,
    };
}

function allocationCard(
    overrides: Partial<WebsiteAllocationCard> = {},
): WebsiteAllocationCard {
    const term = {
        included: true,
        starts_at: '2026-10-10T09:30:00+06:00',
        expires_at: '2027-10-10T09:30:00+06:00',
        days_remaining: 365,
        package_based: true,
        provider: null,
    };

    return {
        id: '01ALLOCATION',
        slot: 1,
        name: 'Karim Traders',
        website: null,
        domain_name: null,
        partner_name: 'Karim Traders',
        package_name: 'Growth',
        status: 'pending_setup',
        status_label: 'Pending setup',
        status_tone: 'info',
        domain: term,
        hosting: term,
        ...overrides,
    };
}

describe('the websites a package allocates', () => {
    it('shows a pending allocation with its package-based dates and a way to set it up', () => {
        render(
            <Websites
                allocations={[allocationCard()]}
                websites={[]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: 1,
                    used: 0,
                    remaining: 1,
                }}
                can={{ create: true }}
            />,
        );

        const card = screen.getByRole('article');

        expect(
            within(card).getByText('Karim Traders', { selector: 'h3' }),
        ).toBeInTheDocument();
        expect(within(card).getByText('Pending setup')).toBeInTheDocument();
        expect(
            within(card).getByText('website.allocation.not_set_up'),
        ).toBeInTheDocument();
        expect(
            within(card).getAllByText('website.allocation.package_based'),
        ).toHaveLength(2);
        expect(
            within(card).getAllByText('website.allocation.days_left'),
        ).toHaveLength(2);
        expect(
            within(card).getByRole('link', {
                name: 'website.allocation.set_up',
            }),
        ).toBeInTheDocument();
    });

    it('shows a set-up website once, on its card, with its address and domain', () => {
        render(
            <Websites
                allocations={[
                    allocationCard({
                        name: 'Nasrin Fashion',
                        website: {
                            id: '01WEBSITE',
                            host: 'nasrin.example.com',
                            url: 'https://nasrin.example.com',
                        },
                        domain_name: 'nasrin.example.com',
                        status: 'active',
                        status_label: 'Active',
                        status_tone: 'success',
                        domain: {
                            included: true,
                            starts_at: '2026-10-10T09:30:00+06:00',
                            expires_at: '2027-10-10T09:30:00+06:00',
                            days_remaining: 365,
                            package_based: false,
                            provider: {
                                started_at: '2027-01-05T12:00:00+06:00',
                                expires_at: '2028-01-05T12:00:00+06:00',
                            },
                        },
                    }),
                ]}
                websites={[summary()]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: 1,
                    used: 1,
                    remaining: 0,
                }}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'nasrin.example.com' }),
        ).toHaveAttribute('href', 'https://nasrin.example.com');
        expect(
            screen.getByRole('link', { name: 'website.allocation.open' }),
        ).toBeInTheDocument();
        // The website is on its card, not repeated in the plain list below.
        expect(
            screen.queryByText('website.index.other_websites'),
        ).not.toBeInTheDocument();
        // Hosting is still package-based; the registered domain is not.
        expect(
            screen.getAllByText('website.allocation.package_based'),
        ).toHaveLength(1);
        // The registrar's own record is shown as its own block, beside — not
        // instead of — the package period.
        expect(
            document.querySelectorAll('[data-test="provider-record"]'),
        ).toHaveLength(1);
        expect(
            screen.getAllByText('website.allocation.package_period'),
        ).toHaveLength(2);
    });

    it('says when a term has ended', () => {
        render(
            <Websites
                allocations={[
                    allocationCard({
                        status: 'expired',
                        status_label: 'Expired',
                        status_tone: 'danger',
                        hosting: {
                            included: true,
                            starts_at: '2025-10-01T09:30:00+06:00',
                            expires_at: '2026-10-01T09:30:00+06:00',
                            days_remaining: -9,
                            package_based: true,
                            provider: null,
                        },
                    }),
                ]}
                websites={[]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: 1,
                    used: 0,
                    remaining: 1,
                }}
                can={{ create: true }}
            />,
        );

        expect(screen.getByText('Expired')).toBeInTheDocument();
        expect(
            screen.getByText('website.allocation.expired_days_ago'),
        ).toBeInTheDocument();
        // A lapsed term is renewed, not set up.
        expect(
            screen.queryByRole('link', { name: 'website.allocation.set_up' }),
        ).not.toBeInTheDocument();
    });

    it('writes the days left in the reader’s own digits', () => {
        websitesPage.props = {
            locale: { current: 'bn', direction: 'ltr', available: [] },
            translations: {
                website: { allocation: { days_left: ':days দিন বাকি' } },
            },
        };

        try {
            render(
                <Websites
                    allocations={[allocationCard()]}
                    websites={[]}
                    entitlement={{
                        allowed: true,
                        expired: false,
                        limit: 1,
                        used: 0,
                        remaining: 1,
                    }}
                    can={{ create: true }}
                />,
            );

            expect(screen.getAllByText('৩৬৫ দিন বাকি')).toHaveLength(2);
        } finally {
            websitesPage.props = { translations: {} };
        }
    });
});

describe('the account’s websites', () => {
    it('says what the package allows rather than hiding the door', () => {
        render(
            <Websites
                allocations={[]}
                websites={[]}
                entitlement={{
                    allowed: false,
                    expired: false,
                    limit: 0,
                    used: 0,
                    remaining: 0,
                }}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByText('website.index.not_entitled'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'website.index.request' }),
        ).not.toBeInTheDocument();
    });

    it('offers the request only while the package has room left', () => {
        const { unmount } = render(
            <Websites
                allocations={[]}
                websites={[]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: 1,
                    used: 0,
                    remaining: 1,
                }}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getAllByRole('link', { name: 'website.index.request' })
                .length,
        ).toBeGreaterThan(0);

        unmount();

        render(
            <Websites
                allocations={[]}
                websites={[summary()]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: 1,
                    used: 1,
                    remaining: 0,
                }}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByText('website.index.limit_reached'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'website.index.request' }),
        ).not.toBeInTheDocument();
    });

    it('offers no request on an expired website entitlement, and says why', () => {
        render(
            <Websites
                allocations={[
                    allocationCard({
                        status: 'expired',
                        status_label: 'Expired',
                        status_tone: 'danger',
                    }),
                ]}
                websites={[]}
                entitlement={{
                    allowed: true,
                    expired: true,
                    limit: 1,
                    used: 0,
                    remaining: 0,
                }}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByText('website.index.entitlement_expired'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('website.index.limit_reached'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'website.index.request' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'website.allocation.set_up' }),
        ).not.toBeInTheDocument();
    });

    it('writes the allowance in the reader’s own digits', () => {
        const entitlement = {
            allowed: true,
            expired: false,
            limit: 1,
            used: 1,
            remaining: 0,
        };
        const translations = {
            website: {
                index: { limit_reached: ':limit টি ওয়েবসাইট অনুমোদিত' },
            },
        };

        websitesPage.props = {
            locale: { current: 'bn', direction: 'ltr', available: [] },
            translations,
        };

        try {
            const { unmount } = render(
                <Websites
                    allocations={[]}
                    websites={[summary()]}
                    entitlement={entitlement}
                    can={{ create: true }}
                />,
            );

            expect(
                screen.getByText('১ টি ওয়েবসাইট অনুমোদিত'),
            ).toBeInTheDocument();

            unmount();

            websitesPage.props = {
                locale: { current: 'en', direction: 'ltr', available: [] },
                translations,
            };

            render(
                <Websites
                    allocations={[]}
                    websites={[summary()]}
                    entitlement={entitlement}
                    can={{ create: true }}
                />,
            );

            expect(
                screen.getByText('1 টি ওয়েবসাইট অনুমোদিত'),
            ).toBeInTheDocument();
        } finally {
            websitesPage.props = { translations: {} };
        }
    });

    it('says which websites have something to pay', () => {
        render(
            <Websites
                allocations={[]}
                websites={[
                    summary({
                        status: 'deposit_pending',
                        status_label: 'Deposit pending',
                        outstanding_charges: 2,
                    }),
                ]}
                entitlement={{
                    allowed: true,
                    expired: false,
                    limit: null,
                    used: 1,
                    remaining: null,
                }}
                can={{ create: true }}
            />,
        );

        expect(screen.getByText('Deposit pending')).toBeInTheDocument();
        expect(
            screen.getByText('website.index.outstanding'),
        ).toBeInTheDocument();
    });
});

describe('the domain and hosting staff recorded', () => {
    const entitlement = {
        allowed: true,
        expired: false,
        limit: 1,
        used: 1,
        remaining: 0,
    };
    const day = (value: string) =>
        new Date(value).toLocaleDateString('en', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });

    it('shows what was recorded, with when it started and when it runs out', () => {
        websitesPage.props = {
            locale: { current: 'en', direction: 'ltr', available: [] },
            translations: {
                website: {
                    index: {
                        domain_line:
                            'Domain :domain · registered :registered · expires :expires',
                        hosting_line:
                            'Hosting :plan · started :started · expires :expires',
                    },
                },
            },
        };

        try {
            render(
                <Websites
                    allocations={[]}
                    websites={[
                        summary({
                            host: 'karim.com',
                            domain_record: {
                                domain: 'karim.com',
                                status: 'active',
                                status_label: 'Active',
                                registered_at: '2026-10-12T10:00:00+06:00',
                                expires_at: '2027-10-12T10:00:00+06:00',
                                days_remaining: 365,
                            },
                            hosting_record: {
                                plan: 'Standard',
                                status: 'active',
                                status_label: 'Active',
                                started_at: '2026-10-12T10:00:00+06:00',
                                expires_at: '2027-04-12T10:00:00+06:00',
                                days_remaining: 182,
                            },
                        }),
                    ]}
                    entitlement={entitlement}
                    can={{ create: true }}
                />,
            );

            expect(
                screen.getByText(
                    `Domain karim.com · registered ${day('2026-10-12T10:00:00+06:00')} · expires ${day('2027-10-12T10:00:00+06:00')}`,
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    `Hosting Standard · started ${day('2026-10-12T10:00:00+06:00')} · expires ${day('2027-04-12T10:00:00+06:00')}`,
                ),
            ).toBeInTheDocument();
        } finally {
            websitesPage.props = { translations: {} };
        }
    });

    it('says a request has neither yet, rather than leaving a blank', () => {
        render(
            <Websites
                allocations={[]}
                websites={[
                    summary({
                        status: 'setup_pending',
                        status_label: 'Setup pending',
                    }),
                ]}
                entitlement={entitlement}
                can={{ create: true }}
            />,
        );

        expect(
            screen.getByText('website.index.domain_pending'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('website.index.hosting_pending'),
        ).toBeInTheDocument();
    });

    it('names the hosting plan on a package website card', () => {
        const card = allocationCard();

        render(
            <Websites
                allocations={[
                    allocationCard({
                        hosting: {
                            ...card.hosting,
                            package_based: false,
                            provider: {
                                plan: 'Standard',
                                started_at: '2026-10-12T10:00:00+06:00',
                                expires_at: '2027-10-12T10:00:00+06:00',
                            },
                        },
                    }),
                ]}
                websites={[]}
                entitlement={entitlement}
                can={{ create: true }}
            />,
        );

        expect(screen.getByText('Standard')).toBeInTheDocument();
        expect(
            screen.getByText('website.allocation.provider_plan'),
        ).toBeInTheDocument();
    });
});
