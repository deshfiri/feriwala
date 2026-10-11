// @vitest-environment jsdom

import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { page } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        usePage: () => page,
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children: ReactNode;
        }) =>
            createElement(
                'a',
                {
                    href: typeof href === 'string' ? href : href.url,
                    ...rest,
                },
                children,
            ),
    };
});

vi.mock('@/components/ui/tooltip', async () => {
    const { createElement, Fragment } = await import('react');

    return {
        Tooltip: ({ children }: { children: ReactNode }) =>
            createElement(Fragment, null, children),
        TooltipTrigger: ({ children }: { children: ReactNode }) =>
            createElement(Fragment, null, children),
        TooltipContent: () => null,
    };
});

const { default: PartnerHeaderTools, BALANCE_REVEAL_MS } =
    await import('./partner-header-tools');

const money = { amount: '800.00', currency: 'BDT', formatted: '৳800.00' };

const translations = {
    header: {
        cart: { empty: 'Cart', with_items: 'Cart, :count item(s)' },
        wallet: {
            show: 'Show wallet balance',
            hide: 'Hide wallet balance',
            label: 'Available balance',
            loading: 'Loading…',
            failed: 'Could not load the balance.',
            open: 'Open wallet',
        },
    },
};

const account = (overrides: Record<string, unknown> = {}) => ({
    status: 'active',
    allowsWholesale: true,
    wholesaleCartLines: 0,
    ...overrides,
});

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ usable: money }),
            }),
        ),
    );
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const renderTools = (acct: Record<string, unknown> | null) => {
    page.props = {
        translations,
        locale: { current: 'en' },
        account: acct,
    };

    return render(<PartnerHeaderTools />);
};

describe('the cart shortcut', () => {
    it('shows the server-supplied count on the badge and in its name', () => {
        renderTools(account({ wholesaleCartLines: 3 }));

        expect(
            screen.getByRole('link', { name: 'Cart, 3 item(s)' }),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="header-cart-count"]'),
        ).toHaveTextContent('3');
    });

    it('shows no badge for an empty cart', () => {
        renderTools(account());

        expect(screen.getByRole('link', { name: 'Cart' })).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="header-cart-count"]'),
        ).toBeNull();
    });

    it('is absent where wholesale is not included, and for anyone without an account', () => {
        renderTools(account({ allowsWholesale: false }));
        expect(screen.queryByRole('link', { name: /Cart/ })).toBeNull();

        cleanup();
        renderTools(null);
        expect(screen.queryByRole('link', { name: /Cart/ })).toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
    });
});

describe('the wallet eye', () => {
    it('keeps the balance hidden, and unfetched, until it is pressed', () => {
        renderTools(account());

        expect(screen.queryByText('৳800.00')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('reveals the balance, then hides it again by itself', async () => {
        renderTools(account());

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Show wallet balance' }),
            );
        });

        expect(screen.getByText('৳800.00')).toBeInTheDocument();
        expect(screen.getByText('Available balance')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open wallet' }),
        ).toBeInTheDocument();

        await act(async () => {
            vi.advanceTimersByTime(BALANCE_REVEAL_MS + 100);
        });

        expect(screen.queryByText('৳800.00')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Show wallet balance' }),
        ).toBeInTheDocument();
    });

    it('hides on a second press, and on Escape', async () => {
        renderTools(account());

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Show wallet balance' }),
            );
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Hide wallet balance' }),
            );
        });

        expect(screen.queryByText('৳800.00')).toBeNull();

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Show wallet balance' }),
            );
        });
        await act(async () => {
            fireEvent.keyDown(window, { key: 'Escape' });
        });

        expect(screen.queryByText('৳800.00')).toBeNull();
    });

    it('never shows a figure it could not load', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: false })),
        );
        renderTools(account());

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Show wallet balance' }),
            );
        });

        expect(
            screen.getByText('Could not load the balance.'),
        ).toBeInTheDocument();
        expect(screen.queryByText('৳800.00')).toBeNull();
    });

    it('is absent until the account is active', () => {
        renderTools(account({ status: 'pending' }));

        expect(
            screen.queryByRole('button', { name: /wallet balance/ }),
        ).toBeNull();
    });
});
