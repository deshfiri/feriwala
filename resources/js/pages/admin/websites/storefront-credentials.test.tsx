// @vitest-environment jsdom

import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CredentialRow } from '@/components/website-credential-list';
import type { IssuedCredential } from './storefront-credentials';

const post = vi.fn();
const reload = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { createElement, useState } = await import('react');

    return {
        Form: ({
            action,
            children,
            className,
        }: {
            action: string;
            className?: string;
            children: (state: {
                errors: Record<string, string>;
                processing: boolean;
            }) => unknown;
        }) =>
            createElement(
                'form',
                { action, method: 'post', className },
                children({ errors: {}, processing: false }) as never,
            ),
        useHttp: <T extends object>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setState((current) => ({ ...current, [key]: value })),
                reset: () => setState(initial),
                post,
            };
        },
        router: { reload },
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: StorefrontCredentials } =
    await import('./storefront-credentials');

const credential = (overrides: Partial<CredentialRow> = {}): CredentialRow => ({
    id: '01CREDENTIAL',
    name: 'Production storefront',
    key_id: 'wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3B',
    secret_hint: 'd265',
    scopes: ['catalog:read'],
    last_used_at: null,
    rotated_at: null,
    previous_secret_expires_at: null,
    revoked_at: null,
    revoked_reason: null,
    created_at: '2026-09-16T10:00:00+06:00',
    ...overrides,
});

const scopes = [
    { value: 'catalog:read', label: 'Read the catalogue' },
    { value: 'orders:write', label: 'Submit orders' },
];

const presets = [
    { value: 'catalogue', label: 'Catalogue only', scopes: ['catalog:read'] },
];

const connection = (originAccepted = true) => ({
    erp_origin: originAccepted ? 'https://erp.test' : 'http://127.0.0.1:8000',
    origin_accepted: originAccepted,
    website_id: '01WEBSITE',
    primary_host: 'nasrin.feriwala.shop',
});

const secret = 'c'.repeat(64);

const issued: IssuedCredential = {
    key_id: 'wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3D',
    secret,
    scopes: ['catalog:read'],
    env: `BANIJ_ERP_BASE_URL=https://erp.test\nBANIJ_CREDENTIAL_KEY_ID=wsk_01J8Z9K3M7QX2P4R6T8V0W1Y3D\nBANIJ_CREDENTIAL_SECRET=${secret}\n`,
    origin_accepted: true,
    previous_secret_expires_at: null,
};

beforeEach(() => {
    post.mockReset();
    reload.mockReset();
});

describe("managing a storefront's credentials", () => {
    it('offers rotation and revocation only on a credential that still works', () => {
        render(
            <StorefrontCredentials
                websiteId="01WEBSITE"
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
                presets={presets}
                connection={connection()}
            />,
        );

        expect(
            screen.getAllByRole('button', {
                name: 'website.integration.rotate',
            }),
        ).toHaveLength(1);
        expect(screen.getByText('Leaked in a screenshot.')).toBeInTheDocument();
        expect(screen.getByText(/01WEBSITE/)).toBeInTheDocument();
    });

    it('cannot issue until a scope is chosen, and a preset chooses them', () => {
        render(
            <StorefrontCredentials
                websiteId="01WEBSITE"
                credentials={[]}
                scopes={scopes}
                presets={presets}
                connection={connection()}
            />,
        );

        const issue = screen.getByRole('button', {
            name: 'website.integration.issue',
        });

        expect(issue).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Catalogue only' }));

        expect(issue).toBeEnabled();
        expect(
            screen.getByRole('checkbox', { name: /Read the catalogue/ }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /Submit orders/ }),
        ).not.toBeChecked();
    });

    it('shows the environment block once, and forgets the secret when closed', async () => {
        post.mockResolvedValue(issued);

        render(
            <StorefrontCredentials
                websiteId="01WEBSITE"
                credentials={[]}
                scopes={scopes}
                presets={presets}
                connection={connection()}
            />,
        );

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Production storefront' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Catalogue only' }));

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'website.integration.issue',
                }),
            );
        });

        expect(post).toHaveBeenCalledWith(
            '/admin/websites/01WEBSITE/credentials',
        );
        expect(reload).toHaveBeenCalledWith({ only: ['credentials'] });
        expect(screen.getByText(new RegExp(secret))).toBeInTheDocument();

        act(() => {
            screen
                .getByRole('button', { name: 'website.integration.stored_it' })
                .click();
        });

        expect(screen.queryByText(new RegExp(secret))).not.toBeInTheDocument();
    });

    it('warns when the ERP address is not one a storefront accepts', () => {
        render(
            <StorefrontCredentials
                websiteId="01WEBSITE"
                credentials={[]}
                scopes={scopes}
                presets={presets}
                connection={connection(false)}
            />,
        );

        expect(
            screen.getByText('website.credentials.origin_not_https'),
        ).toBeInTheDocument();
    });
});
