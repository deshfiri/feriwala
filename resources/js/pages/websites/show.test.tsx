// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WebsiteDetail } from '@/types/website';

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
        Form: ({
            action,
            children,
        }: {
            action: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, method: 'post' },
                children({ errors: {}, processing: false }) as never,
            ),
        useForm: () => ({ post: vi.fn(), processing: false, errors: {} }),
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WebsiteShow } = await import('./show');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

function website(overrides: Partial<WebsiteDetail> = {}): WebsiteDetail {
    return {
        id: '01WEBSITE',
        name: 'Nasrin Fashion',
        host: 'nasrin.feriwala.shop',
        status: 'setup_pending',
        status_label: 'Setup pending',
        is_live: false,
        connection_health: 'unknown',
        last_synced_at: null,
        outstanding_charges: 1,
        account: null,
        created_at: '2026-09-16T10:00:00+06:00',
        subdomain: 'nasrin',
        domain: null,
        tagline: null,
        about: null,
        theme: 'classic',
        primary_color: '#111111',
        secondary_color: '#f97316',
        logo_url: null,
        banner_url: null,
        contact: { email: null, phone: null, address: null },
        charges_summary: {
            setup: money('5000.00'),
            domain: money('1500.00'),
            hosting: money('2000.00'),
            required_deposit: money('0.00'),
            minimum_balance: money('0.00'),
        },
        lifecycle: {
            activated_at: null,
            expires_at: null,
            grace_ends_at: null,
            suspended_at: null,
            suspension_reason: null,
            maintenance_message: null,
        },
        connection: {
            health: 'unknown',
            health_label: 'Not connected yet',
            api_connected_at: null,
            webhook_connected_at: null,
            last_synced_at: null,
        },
        charges: [
            {
                id: '01CHARGE',
                type: 'setup',
                type_label: 'Website setup',
                status: 'due',
                status_label: 'Due',
                amount: money('5000.00'),
                due_at: '2026-09-16T10:00:00+06:00',
                paid_at: null,
                period_start: null,
                period_end: null,
            },
        ],
        domains: [],
        hostings: [],
        history: [
            {
                id: 1,
                previous_status: null,
                new_status: 'setup_pending',
                new_status_label: 'Setup pending',
                changed_at: '2026-09-16T10:00:00+06:00',
                note: 'Your website has been requested.',
                source: null,
                reason: null,
                internal_note: null,
                changed_by: null,
            },
        ],
        ...overrides,
    };
}

const wallet = { available: money('10000.00'), currency: 'BDT' };

describe('a partner’s own website', () => {
    it('leads with where it stands and what it is waiting for', () => {
        render(
            <WebsiteShow
                website={website()}
                wallet={wallet}
                can={{ pay: true, manage: true }}
            />,
        );

        // Once as the status, once in the history beneath it.
        expect(screen.getAllByText('Setup pending')).toHaveLength(2);

        // Beside the name, and again under the connection it is reached on.
        expect(screen.getAllByText('nasrin.feriwala.shop')).toHaveLength(2);
        expect(screen.getByText('Website setup')).toBeInTheDocument();
        expect(screen.getByText('৳5000.00')).toBeInTheDocument();
    });

    it('offers to pay an outstanding charge, and nothing to somebody who may not', () => {
        const { unmount } = render(
            <WebsiteShow
                website={website()}
                wallet={wallet}
                can={{ pay: true, manage: false }}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'website.show.pay' }),
        ).toBeInTheDocument();

        unmount();

        render(
            <WebsiteShow
                website={website()}
                wallet={wallet}
                can={{ pay: false, manage: false }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'website.show.pay' }),
        ).not.toBeInTheDocument();
    });

    it('shows a suspended shop the reason it is down', () => {
        render(
            <WebsiteShow
                website={website({
                    status: 'suspended',
                    status_label: 'Suspended',
                    lifecycle: {
                        activated_at: null,
                        expires_at: null,
                        grace_ends_at: null,
                        suspended_at: '2026-09-16T10:00:00+06:00',
                        suspension_reason: 'Contact support to restore it.',
                        maintenance_message: null,
                    },
                })}
                wallet={wallet}
                can={{ pay: true, manage: true }}
            />,
        );

        expect(
            screen.getByText('Contact support to restore it.'),
        ).toBeInTheDocument();
    });

    it('turns maintenance on for a live shop and off again for one under it', () => {
        const { unmount } = render(
            <WebsiteShow
                website={website({ status: 'active', status_label: 'Active' })}
                wallet={wallet}
                can={{ pay: true, manage: true }}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'website.show.maintenance_on' }),
        ).toBeInTheDocument();

        unmount();

        render(
            <WebsiteShow
                website={website({
                    status: 'maintenance',
                    status_label: 'Maintenance',
                })}
                wallet={wallet}
                can={{ pay: true, manage: true }}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: 'website.show.maintenance_off',
            }),
        ).toBeInTheDocument();
    });

    it('never has a registrar or a host to render', () => {
        const page = website({
            domains: [
                {
                    id: 1,
                    domain: 'nasrin-fashion.com.bd',
                    status: 'active',
                    status_label: 'Active',
                    registered_at: '2026-09-01T00:00:00+06:00',
                    expires_at: '2027-09-01T00:00:00+06:00',
                    days_remaining: 350,
                    auto_renew: false,
                    fee: money('1500.00'),
                    // The partner's props carry no supplier at all (§16.3).
                    registrar: null,
                },
            ],
            hostings: [
                {
                    id: 1,
                    plan: 'Storefront standard',
                    status: 'active',
                    status_label: 'Active',
                    started_at: '2026-09-01T00:00:00+06:00',
                    expires_at: '2027-09-01T00:00:00+06:00',
                    days_remaining: 350,
                    auto_renew: false,
                    fee: money('2000.00'),
                    provider: null,
                },
            ],
        });

        render(
            <WebsiteShow
                website={page}
                wallet={wallet}
                can={{ pay: true, manage: true }}
            />,
        );

        expect(screen.getByText('nasrin-fashion.com.bd')).toBeInTheDocument();
        expect(screen.getByText('Storefront standard')).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: 'website.show.renew' }),
        ).toHaveLength(2);
    });
});
