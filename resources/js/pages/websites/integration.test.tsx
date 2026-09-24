// @vitest-environment jsdom

import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WebsiteDetail } from '@/types/website';
import type {
    ApiCallRow,
    CredentialRow,
    DeliveryRow,
    FailureRow,
    WebhookRow,
} from './integration';

const listeners: ((event: Event) => void)[] = [];

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
        router: {
            on: (_name: string, callback: (event: Event) => void) => {
                listeners.push(callback);

                return () => undefined;
            },
        },
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: WebsiteIntegration } = await import('./integration');

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
} satisfies WebsiteDetail;

const credential = (overrides: Partial<CredentialRow> = {}): CredentialRow => ({
    id: '01CREDENTIAL',
    name: 'Production storefront',
    key_id: 'wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3B',
    secret_hint: 'd265',
    scopes: ['catalog:read', 'inventory:read'],
    last_used_at: null,
    rotated_at: null,
    previous_secret_expires_at: null,
    revoked_at: null,
    revoked_reason: null,
    created_at: '2026-09-16T10:00:00+06:00',
    ...overrides,
});

const scopes = [
    { value: 'catalog:read', label: 'Read the catalogue', default: true },
    {
        value: 'inventory:read',
        label: 'Read stock availability',
        default: true,
    },
    { value: 'orders:write', label: 'Submit orders', default: false },
];

const calls: ApiCallRow[] = [
    {
        request_id: '01REQUEST',
        method: 'GET',
        path: '/api/storefront/v1/products',
        status: 401,
        error_code: 'invalid_signature',
        duration_ms: 12,
        created_at: '2026-09-16T10:00:00+06:00',
    },
];

describe('connecting a storefront', () => {
    it('shows a credential by its hint, never its secret', () => {
        render(
            <WebsiteIntegration
                website={website}
                credentials={[credential()]}
                scopes={scopes}
                recent_calls={calls}
                api_base="https://erp.test/api/storefront/v1"
            />,
        );

        expect(
            screen.getByText(/wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3B/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('website.integration.secret_once'),
        ).not.toBeInTheDocument();
    });

    it('shows a freshly issued secret once, from the flash, until it is dismissed', () => {
        render(
            <WebsiteIntegration
                website={website}
                credentials={[credential()]}
                scopes={scopes}
                recent_calls={[]}
                api_base="https://erp.test/api/storefront/v1"
            />,
        );

        act(() => {
            listeners.forEach((listener) =>
                listener(
                    new CustomEvent('flash', {
                        detail: {
                            flash: {
                                credential: {
                                    key_id: 'wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3B',
                                    secret: 'a'.repeat(64),
                                },
                            },
                        },
                    }),
                ),
            );
        });

        expect(screen.getByText('a'.repeat(64))).toBeInTheDocument();

        act(() => {
            screen
                .getByRole('button', { name: 'website.integration.stored_it' })
                .click();
        });

        expect(screen.queryByText('a'.repeat(64))).not.toBeInTheDocument();
    });

    it('offers rotation and revocation only on a credential that still works', () => {
        render(
            <WebsiteIntegration
                website={website}
                credentials={[
                    credential(),
                    credential({
                        id: '01REVOKED',
                        key_id: 'wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3C',
                        revoked_at: '2026-09-16T11:00:00+06:00',
                        revoked_reason: 'Leaked in a screenshot.',
                    }),
                ]}
                scopes={scopes}
                recent_calls={calls}
                api_base="https://erp.test/api/storefront/v1"
            />,
        );

        expect(
            screen.getAllByRole('button', {
                name: 'website.integration.rotate',
            }),
        ).toHaveLength(1);
        expect(screen.getByText('Leaked in a screenshot.')).toBeInTheDocument();
        expect(screen.getByText(/invalid_signature/)).toBeInTheDocument();
    });
});

const webhook: WebhookRow = {
    url: 'https://nasrin.example.com/feriwala/webhooks',
    secret_hint: '9f3c',
    is_active: true,
    rotated_at: null,
    previous_secret_expires_at: null,
};

const failure: FailureRow = {
    id: '01FAILURE',
    event_id: '01EVENTFAILED',
    event_type: 'product.updated',
    error: 'The storefront answered 500.',
    attempts: 9,
    retry_count: 0,
    failed_at: '2026-09-16T12:00:00+06:00',
};

const delivery: DeliveryRow = {
    event_id: '01EVENTRETRY',
    event_type: 'inventory.updated',
    state: 'retrying',
    attempt: 2,
    response_status: 503,
    last_error: 'The storefront answered 503.',
    next_retry_at: '2026-09-16T12:05:00+06:00',
    delivered_at: null,
    created_at: '2026-09-16T12:00:00+06:00',
};

describe('telling a storefront what changed', () => {
    it('shows the webhook by its address and hint, with its deliveries and failures', () => {
        render(
            <WebsiteIntegration
                website={website}
                credentials={[]}
                scopes={scopes}
                recent_calls={[]}
                api_base="https://erp.test/api/storefront/v1"
                webhook={webhook}
                deliveries={[delivery]}
                failures={[failure]}
            />,
        );

        expect(
            screen.getByDisplayValue(
                'https://nasrin.example.com/feriwala/webhooks',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText(/9f3c/)).toBeInTheDocument();
        expect(
            screen.getByText('website.integration.delivery_states.retrying'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The storefront answered 500.'),
        ).toBeInTheDocument();

        // A failure is retried by its event identifier, so the storefront
        // recognises the same event.
        const retry = screen.getByRole('button', {
            name: 'website.integration.retry',
        });
        expect(retry.closest('form')?.getAttribute('action')).toContain(
            '/deliveries/01EVENTFAILED/retry',
        );

        expect(
            screen.getByRole('button', {
                name: 'website.integration.sync_now',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'website.integration.disable' }),
        ).toBeInTheDocument();
    });

    it('offers only the address form before a webhook exists', () => {
        render(
            <WebsiteIntegration
                website={website}
                credentials={[]}
                scopes={scopes}
                recent_calls={[]}
                api_base="https://erp.test/api/storefront/v1"
            />,
        );

        expect(
            screen.getByRole('button', {
                name: 'website.integration.webhook_save',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'website.integration.sync_now',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('website.integration.no_failures'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('website.integration.no_deliveries'),
        ).toBeInTheDocument();
    });

    it('shows a new signing secret once, from the flash, until it is dismissed', () => {
        listeners.length = 0;

        render(
            <WebsiteIntegration
                website={website}
                credentials={[]}
                scopes={scopes}
                recent_calls={[]}
                api_base="https://erp.test/api/storefront/v1"
                webhook={webhook}
            />,
        );

        act(() => {
            listeners.forEach((listener) =>
                listener(
                    new CustomEvent('flash', {
                        detail: { flash: { webhook_secret: 'b'.repeat(64) } },
                    }),
                ),
            );
        });

        expect(screen.getByText('b'.repeat(64))).toBeInTheDocument();

        act(() => {
            screen
                .getByRole('button', { name: 'website.integration.stored_it' })
                .click();
        });

        expect(screen.queryByText('b'.repeat(64))).not.toBeInTheDocument();
    });
});
