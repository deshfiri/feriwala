// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { WebsiteSummary } from '@/types/website';

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
        usePage: () => ({ props: { translations: {} } }),
    };
});

const { default: Websites } = await import('./index');

function summary(overrides: Partial<WebsiteSummary> = {}): WebsiteSummary {
    return {
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

describe('the account’s websites', () => {
    it('says what the package allows rather than hiding the door', () => {
        render(
            <Websites
                websites={[]}
                entitlement={{
                    allowed: false,
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
                websites={[]}
                entitlement={{
                    allowed: true,
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
                websites={[summary()]}
                entitlement={{
                    allowed: true,
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

    it('says which websites have something to pay', () => {
        render(
            <Websites
                websites={[
                    summary({
                        status: 'deposit_pending',
                        status_label: 'Deposit pending',
                        outstanding_charges: 2,
                    }),
                ]}
                entitlement={{
                    allowed: true,
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
