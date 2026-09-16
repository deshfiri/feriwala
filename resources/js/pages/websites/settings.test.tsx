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

const { default: WebsiteSettings } = await import('./settings');

const money = (minor: number) => ({
    minor_units: minor,
    currency: 'BDT',
    decimal: (minor / 100).toFixed(2),
    formatted: `৳${(minor / 100).toFixed(2)}`,
});

function website(overrides: Partial<WebsiteDetail> = {}): WebsiteDetail {
    return {
        id: '01WEBSITE',
        name: 'Nasrin Fashion',
        host: 'nasrin.feriwala.shop',
        status: 'active',
        status_label: 'Active',
        is_live: true,
        connection_health: 'healthy',
        last_synced_at: null,
        outstanding_charges: 0,
        account: null,
        created_at: '2026-09-16T10:00:00+06:00',
        subdomain: 'nasrin',
        domain: null,
        tagline: 'Everyday cotton',
        about: null,
        theme: 'classic',
        primary_color: '#111111',
        secondary_color: '#f97316',
        logo_url: null,
        banner_url: null,
        contact: {
            email: 'hello@nasrin.example',
            phone: null,
            address: null,
        },
        charges_summary: {
            setup: money(0),
            domain: money(0),
            hosting: money(0),
            required_deposit: money(0),
            minimum_balance: money(0),
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
            health: 'healthy',
            health_label: 'Healthy',
            api_connected_at: null,
            webhook_connected_at: null,
            last_synced_at: null,
        },
        charges: [],
        domains: [],
        hostings: [],
        history: [],
        ...overrides,
    };
}

const themes = [
    { value: 'classic', label: 'Classic' },
    { value: 'modern', label: 'Modern' },
    { value: 'minimal', label: 'Minimal' },
];

const limits = {
    max_bytes: 2 * 1024 * 1024,
    accepted: ['image/jpeg', 'image/png', 'image/webp'],
};

describe('website settings', () => {
    it('opens with what the shop already says about itself', () => {
        render(
            <WebsiteSettings
                website={website()}
                themes={themes}
                image_limits={limits}
            />,
        );

        expect(screen.getByDisplayValue('Nasrin Fashion')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Everyday cotton')).toBeInTheDocument();
        expect(
            screen.getByDisplayValue('hello@nasrin.example'),
        ).toBeInTheDocument();
        expect(screen.getByDisplayValue('#111111')).toBeInTheDocument();
    });

    it('offers to remove an image only once there is one', () => {
        const { unmount } = render(
            <WebsiteSettings
                website={website()}
                themes={themes}
                image_limits={limits}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'website.settings.remove' }),
        ).not.toBeInTheDocument();

        unmount();

        render(
            <WebsiteSettings
                website={website({
                    logo_url: 'https://example.test/storage/websites/logo.png',
                })}
                themes={themes}
                image_limits={limits}
            />,
        );

        expect(
            screen.getAllByRole('button', { name: 'website.settings.remove' }),
        ).toHaveLength(1);
    });

    it('says products come from the catalogue, and offers no way to create one', () => {
        render(
            <WebsiteSettings
                website={website()}
                themes={themes}
                image_limits={limits}
            />,
        );

        expect(
            screen.getByText('website.settings.products_body'),
        ).toBeInTheDocument();

        // §16.3: no product is authored from website management (P5-14).
        expect(
            screen.queryByRole('button', { name: /product/i }),
        ).not.toBeInTheDocument();
    });
});
