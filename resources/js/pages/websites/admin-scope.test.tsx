// @vitest-environment jsdom

import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WebsiteDetail, WebsiteSummary } from '@/types/website';

const setLayoutProps = vi.fn();
const routerGet = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    const { fakeUseForm } = await import('@/test/fake-use-form');

    return {
        useForm: fakeUseForm,
        Head: () => null,
        setLayoutProps: (props: unknown) => setLayoutProps(props),
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
        router: {
            reload: vi.fn(),
            get: (...args: unknown[]) => routerGet(...args),
            visit: vi.fn(),
            on: vi.fn(() => () => {}),
        },
        usePage: () => ({
            props: { translations: {} },
            url: '/admin/websites/01WEBSITE/products',
        }),
    };
});

const { default: WebsiteSettings } = await import('./settings');
const { default: WebsiteCategories } = await import('./categories');
const { default: WebsiteProducts } = await import('./products');

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const summary: WebsiteSummary = {
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
};

const detail: WebsiteDetail = {
    ...summary,
    subdomain: 'nasrin',
    domain: null,
    requested_address: null,
    tagline: 'Everyday cotton',
    about: null,
    theme: 'classic',
    primary_color: '#111111',
    secondary_color: '#f97316',
    logo_url: null,
    banner_url: null,
    contact: { email: 'hello@nasrin.example', phone: null, address: null },
    charges_summary: {
        setup: money('0.00'),
        domain: money('0.00'),
        hosting: money('0.00'),
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
};

const paginator = {
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 20,
    from: null,
    to: null,
    total: 0,
};

const productProps = {
    website: summary,
    selections: paginator,
    filters: { search: null, status: null, category: null },
    categories: [],
    statuses: [],
    publishing: { limit: 5, used: 0, remaining: 5 },
};

const candidate = {
    id: '01PRODUCT',
    name: 'Cotton panjabi',
    sku: 'FW-1043',
    image: null,
    selected: false,
};

const formActions = (container: HTMLElement) =>
    [...container.querySelectorAll('form')].map((form) =>
        form.getAttribute('action'),
    );

beforeEach(() => {
    setLayoutProps.mockClear();
    routerGet.mockClear();
});

describe('a platform administrator editing a partner’s storefront', () => {
    it('saves settings through the admin routes and never a partner’s', () => {
        const { container } = render(
            <WebsiteSettings
                scope="admin"
                website={detail}
                themes={[{ value: 'classic', label: 'Classic' }]}
                image_limits={{ max_bytes: 1024, accepted: ['image/png'] }}
                sms_verification={{
                    enabled: true,
                    locked: false,
                    effective: true,
                }}
            />,
        );

        const actions = formActions(container);

        expect(actions.length).toBeGreaterThan(0);
        expect(
            actions.every((action) =>
                action?.startsWith('/admin/websites/01WEBSITE/settings'),
            ),
        ).toBe(true);
        expect(
            screen
                .getAllByRole('link')
                .some(
                    (link) =>
                        link.getAttribute('href') ===
                        '/admin/websites/01WEBSITE',
                ),
        ).toBe(true);
        expect(setLayoutProps).toHaveBeenCalledWith({
            breadcrumbs: [
                { title: 'nav.partner_websites', href: expect.anything() },
            ],
        });
    });

    it('keeps the partner’s own settings on the partner’s routes', () => {
        const { container } = render(
            <WebsiteSettings
                website={detail}
                themes={[{ value: 'classic', label: 'Classic' }]}
                image_limits={{ max_bytes: 1024, accepted: ['image/png'] }}
                sms_verification={{
                    enabled: true,
                    locked: false,
                    effective: true,
                }}
            />,
        );

        expect(
            formActions(container).every((action) =>
                action?.startsWith('/websites/01WEBSITE/settings'),
            ),
        ).toBe(true);
        expect(setLayoutProps).not.toHaveBeenCalled();
    });

    it('arranges categories through the admin routes', () => {
        const { container } = render(
            <WebsiteCategories
                scope="admin"
                website={summary}
                categories={[
                    {
                        id: '01CATEGORY',
                        name: 'Summer',
                        slug: 'summer',
                        position: 1,
                        is_active: true,
                        products_count: 2,
                    },
                ]}
            />,
        );

        const actions = formActions(container);

        expect(actions).toContain('/admin/websites/01WEBSITE/categories');
        expect(
            actions.every((action) =>
                action?.startsWith('/admin/websites/01WEBSITE/categories'),
            ),
        ).toBe(true);
    });
});

describe('adding products to a partner’s storefront', () => {
    it('offers a search with the matches, and adds through the admin route', () => {
        const { container } = render(
            <WebsiteProducts
                {...productProps}
                scope="admin"
                find="Cotton"
                candidates={[candidate]}
            />,
        );

        expect(container.querySelector('[data-test="picker"]')).not.toBeNull();
        expect(screen.getByText('Cotton panjabi')).toBeInTheDocument();
        expect(formActions(container)).toContain(
            '/admin/websites/01WEBSITE/products',
        );
        // The partner's own catalogue pages are theirs; staff use the search.
        expect(screen.queryByText('website.products.choose')).toBeNull();
    });

    it('says so when nothing the partner can sell matches', () => {
        const { container } = render(
            <WebsiteProducts
                {...productProps}
                scope="admin"
                find="zzz"
                candidates={[]}
            />,
        );

        expect(
            container.querySelector('[data-test="picker-none"]'),
        ).not.toBeNull();
    });

    it('does not offer to add what is already on the storefront', () => {
        const { container } = render(
            <WebsiteProducts
                {...productProps}
                scope="admin"
                find="Cotton"
                candidates={[{ ...candidate, selected: true }]}
            />,
        );

        expect(screen.getByText('website.products.picker_added')).toBeVisible();
        expect(formActions(container)).not.toContain(
            '/admin/websites/01WEBSITE/products',
        );
    });

    it('searches by reloading only the picker and keeping the rest of the query', async () => {
        window.history.replaceState(
            {},
            '',
            '/admin/websites/01WEBSITE/products?status=published',
        );

        render(<WebsiteProducts {...productProps} scope="admin" />);

        fireEvent.change(
            screen.getByLabelText('website.products.picker_search'),
            { target: { value: 'Cotton' } },
        );

        await act(async () => {
            fireEvent.submit(screen.getByRole('search'));
        });

        expect(routerGet).toHaveBeenCalledTimes(1);

        const [url, , options] = routerGet.mock.calls[0] as [
            string,
            unknown,
            { only: string[] },
        ];

        expect(url).toContain('status=published');
        expect(url).toContain('find=Cotton');
        expect(options.only).toEqual(['find', 'candidates']);
    });

    it('shows the partner their own catalogue link and no picker', () => {
        const { container } = render(<WebsiteProducts {...productProps} />);

        expect(container.querySelector('[data-test="picker"]')).toBeNull();
        expect(
            screen.getAllByText('website.products.choose').length,
        ).toBeGreaterThan(0);
    });
});
