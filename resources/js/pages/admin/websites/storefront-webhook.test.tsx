// @vitest-environment jsdom

import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WebhookRow } from '@/components/website-webhook-summary';

const put = vi.fn();
const post = vi.fn();
const reload = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const { createElement, useState } = await import('react');

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
        useHttp: <T extends object>(initial: T) => {
            const [data, setState] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) =>
                    setState((current) => ({ ...current, [key]: value })),
                put,
                post,
            };
        },
        router: { reload },
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: StorefrontWebhook } = await import('./storefront-webhook');

const webhook: WebhookRow = {
    url: 'https://nasrin.example.com/feriwala/webhooks',
    secret_hint: '9f3c',
    is_active: true,
    rotated_at: null,
    previous_secret_expires_at: null,
};

const secret = 'd'.repeat(64);

beforeEach(() => {
    put.mockReset();
    post.mockReset();
    reload.mockReset();
});

describe("managing a storefront's webhook", () => {
    it('shows the signing secret once on first setting, and forgets it when closed', async () => {
        put.mockResolvedValue({
            url: 'https://nasrin.example.com/feriwala/webhooks',
            secret,
            secret_hint: '9f3c',
            previous_secret_expires_at: null,
        });

        render(<StorefrontWebhook websiteId="01WEBSITE" webhook={null} />);

        expect(
            screen.queryByRole('button', {
                name: 'website.integration.rotate',
            }),
        ).not.toBeInTheDocument();

        fireEvent.change(
            screen.getByLabelText('website.integration.webhook_url'),
            {
                target: {
                    value: 'https://nasrin.example.com/feriwala/webhooks',
                },
            },
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'website.integration.webhook_save',
                }),
            );
        });

        expect(put).toHaveBeenCalledWith('/admin/websites/01WEBSITE/webhook');
        expect(reload).toHaveBeenCalledWith({ only: ['webhook'] });
        expect(screen.getByText(secret)).toBeInTheDocument();

        act(() => {
            screen
                .getByRole('button', { name: 'website.integration.stored_it' })
                .click();
        });

        expect(screen.queryByText(secret)).not.toBeInTheDocument();
    });

    it('opens no dialog when changing the address issues no new secret', async () => {
        put.mockResolvedValue({
            url: 'https://nasrin.example.com/feriwala/v2',
            secret: null,
            secret_hint: '9f3c',
            previous_secret_expires_at: null,
        });

        render(<StorefrontWebhook websiteId="01WEBSITE" webhook={webhook} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'website.integration.webhook_save',
                }),
            );
        });

        expect(
            screen.queryByRole('button', {
                name: 'website.integration.stored_it',
            }),
        ).not.toBeInTheDocument();
    });

    it('offers rotation, and switching off only while active', () => {
        const { rerender } = render(
            <StorefrontWebhook websiteId="01WEBSITE" webhook={webhook} />,
        );

        expect(
            screen.getByRole('button', { name: 'website.integration.rotate' }),
        ).toBeInTheDocument();
        expect(
            screen
                .getByRole('button', { name: 'website.integration.disable' })
                .closest('form')
                ?.getAttribute('action'),
        ).toContain('/admin/websites/01WEBSITE/webhook');

        rerender(
            <StorefrontWebhook
                websiteId="01WEBSITE"
                webhook={{ ...webhook, is_active: false }}
            />,
        );

        expect(
            screen.queryByRole('button', {
                name: 'website.integration.disable',
            }),
        ).not.toBeInTheDocument();
    });
});
