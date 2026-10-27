// @vitest-environment jsdom

import { render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        Head: () => null,
        router: { get: vi.fn(), on: vi.fn(() => () => {}) },
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

const { default: MyDamages } = await import('./index');

type Props = ComponentProps<typeof MyDamages>;
type Damage = Props['damages']['data'][number];

const money = (amount: string) => ({
    amount,
    currency: 'BDT',
    formatted: `৳${amount}`,
});

const damage = (overrides: Partial<Damage> = {}): Damage => ({
    id: '01DMG',
    reference: 'DMG-261021-BBBB2222',
    order_number: 'ORD-261015-TEST0001',
    product: {
        sku: 'FW-1043-NVY-M',
        name: 'Cotton panjabi',
        variation: 'Navy / M',
    },
    image: null,
    quantity: 2,
    cause: 'partner_handling',
    cause_label: 'Handling by the Partner',
    approved_loss: money('5000.00'),
    responsibility: 'partner',
    responsibility_label: 'Partner',
    storage_state: 'held_by_banij',
    delivery_state: 'available',
    status: 'finalized',
    status_label: 'Finalized',
    updated_at: '2026-10-22T09:00:00+06:00',
    ...overrides,
});

const page = (data: Damage[]): Props['damages'] => ({
    data,
    current_page: 1,
    last_page: 1,
    per_page: 20,
    from: data.length ? 1 : null,
    to: data.length || null,
    total: data.length,
});

describe('My Damages', () => {
    it('shows each item as a card: what it is, its order, what it cost, where it is, where delivery stands', () => {
        render(<MyDamages damages={page([damage()])} />);

        const card = within(
            screen.getByRole('list', { name: 'damages.title' }),
        ).getByRole('listitem');

        expect(card).toHaveTextContent('DMG-261021-BBBB2222');
        expect(card).toHaveTextContent('Cotton panjabi');
        expect(card).toHaveTextContent('Navy / M');
        expect(card).toHaveTextContent('ORD-261015-TEST0001');
        expect(card).toHaveTextContent('Handling by the Partner');
        expect(card).toHaveTextContent('৳5000.00');
        expect(card).toHaveTextContent('damages.storage_states.held_by_banij');
        expect(card).toHaveTextContent('damages.delivery_states.available');
        expect(
            within(card).getByRole('link', { name: 'DMG-261021-BBBB2222' }),
        ).toHaveAttribute('href', '/my-damages/01DMG');
    });

    it('describes an item that has been sent as sent, never as back on sale', () => {
        render(
            <MyDamages
                damages={page([
                    damage({
                        storage_state: 'in_transit',
                        delivery_state: 'dispatched',
                        status: 'dispatched',
                    }),
                    damage({
                        id: '01DMG2',
                        reference: 'DMG-2',
                        storage_state: 'delivered',
                        delivery_state: 'received',
                    }),
                ])}
            />,
        );

        const text =
            screen.getByRole('list', { name: 'damages.title' }).textContent ??
            '';

        expect(text).toContain('damages.storage_states.in_transit');
        expect(text).toContain('damages.storage_states.delivered');
        expect(text).not.toMatch(/sellable|restock|on sale/i);
    });

    it('says plainly when there is nothing, and does not claim a loss it has not decided', () => {
        const { rerender } = render(<MyDamages damages={page([])} />);

        expect(screen.getByText('damages.empty')).toBeVisible();
        expect(
            screen.queryByRole('list', { name: 'damages.title' }),
        ).toBeNull();

        rerender(
            <MyDamages damages={page([damage({ approved_loss: null })])} />,
        );

        expect(screen.getByText('—', { selector: 'dd' })).toBeDefined();
    });
});
