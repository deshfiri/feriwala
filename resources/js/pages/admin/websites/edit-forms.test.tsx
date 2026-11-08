// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type {
    WebsiteDetail,
    WebsiteDomainRow,
    WebsiteHostingRow,
} from '@/types/website';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WebsiteDetailsForm } = await import('./website-details-form');
const { default: DomainEditForm } = await import('./domain-edit-form');
const { default: HostingEditForm } = await import('./hosting-edit-form');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const website = {
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
    domain: 'nasrin.example',
    requested_address: 'nasrin.com',
    tagline: null,
    about: null,
    theme: 'classic',
    primary_color: '#111111',
    secondary_color: '#f97316',
    logo_url: null,
    banner_url: null,
    contact: { email: null, phone: null, address: null },
    charges_summary: {
        setup: money('0.00'),
        domain: money('0.00'),
        hosting: money('0.00'),
        required_deposit: money('0.00'),
        minimum_balance: money('0.00'),
    },
    lifecycle: {
        activated_at: null,
        expires_at: '2027-01-01T10:00:00+06:00',
        grace_ends_at: null,
        suspended_at: null,
        suspension_reason: null,
        maintenance_message: 'Back at noon.',
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
} satisfies WebsiteDetail;

const domain: WebsiteDomainRow = {
    id: 7,
    domain: 'nasrin.example',
    status: 'active',
    status_label: 'Active',
    registered_at: null,
    expires_at: '2027-06-01T10:00:00+06:00',
    days_remaining: 200,
    auto_renew: true,
    fee: money('1500.00'),
    registrar: 'Namecheap',
};

const hosting: WebsiteHostingRow = {
    id: 3,
    plan: 'Storefront standard',
    status: 'active',
    status_label: 'Active',
    started_at: null,
    expires_at: '2027-06-01T10:00:00+06:00',
    days_remaining: 200,
    auto_renew: false,
    fee: money('2000.00'),
    provider: 'Hetzner',
};

/**
 * Where the form posts and with which verb: a PUT route is posted with
 * `?_method=PUT`, which is how a browser form carries it.
 */
const target = (container: HTMLElement) => {
    const url = new URL(
        container.querySelector('form')?.getAttribute('action') ?? '',
        'http://erp.test',
    );

    return { path: url.pathname, method: url.searchParams.get('_method') };
};

const field = (container: HTMLElement, name: string) =>
    container.querySelector<HTMLInputElement | HTMLTextAreaElement>(
        `[name="${name}"]:not([type="hidden"])`,
    );

describe('correcting a partner’s website, as an administrator', () => {
    it('saves the website’s details to the admin route, with what is recorded now', () => {
        const { container } = render(<WebsiteDetailsForm website={website} />);

        expect(target(container)).toEqual({
            path: '/admin/websites/01WEBSITE/details',
            method: 'PUT',
        });
        expect(field(container, 'domain')?.value).toBe('nasrin.example');
        // The generated subdomain label and the first-asked address are internal:
        // nobody types them, so the form does not carry them.
        expect(field(container, 'subdomain')).toBeNull();
        expect(field(container, 'requested_address')).toBeNull();
        expect(field(container, 'maintenance_message')?.value).toBe(
            'Back at noon.',
        );
        expect(field(container, 'expires_at')?.value).toMatch(
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/,
        );
        // Not recorded yet, so empty rather than a made-up date.
        expect(field(container, 'grace_ends_at')?.value).toBe('');
    });

    it('edits a registered domain on its own route, warning what a new name costs', () => {
        const { container } = render(
            <DomainEditForm websiteId="01WEBSITE" domain={domain} isPrimary />,
        );

        expect(target(container)).toEqual({
            path: '/admin/websites/01WEBSITE/domains/7',
            method: 'PUT',
        });
        expect(field(container, 'domain')?.value).toBe('nasrin.example');
        expect(field(container, 'registrar')?.value).toBe('Namecheap');
        expect(
            screen.getByText('website.edit.domain_warning'),
        ).toBeInTheDocument();

        const renews = container.querySelector<HTMLInputElement>(
            'input[type="checkbox"][name="auto_renew"]',
        );
        const primary = container.querySelector<HTMLInputElement>(
            'input[type="checkbox"][name="make_primary"]',
        );

        expect(renews?.checked).toBe(true);
        expect(primary?.checked).toBe(true);
    });

    it('does not tick “make this the address” for a domain that is not it', () => {
        const { container } = render(
            <DomainEditForm
                websiteId="01WEBSITE"
                domain={domain}
                isPrimary={false}
            />,
        );

        expect(
            container.querySelector<HTMLInputElement>(
                'input[type="checkbox"][name="make_primary"]',
            )?.checked,
        ).toBe(false);
    });

    it('edits a hosting term on its own route', () => {
        const { container } = render(
            <HostingEditForm websiteId="01WEBSITE" hosting={hosting} />,
        );

        expect(target(container)).toEqual({
            path: '/admin/websites/01WEBSITE/hostings/3',
            method: 'PUT',
        });
        expect(field(container, 'plan')?.value).toBe('Storefront standard');
        expect(field(container, 'provider')?.value).toBe('Hetzner');
        expect(
            container.querySelector<HTMLInputElement>(
                'input[type="checkbox"][name="auto_renew"]',
            )?.checked,
        ).toBe(false);
    });
});
